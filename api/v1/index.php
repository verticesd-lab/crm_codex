<?php
ob_start(); // buffer de saída — evita "headers already sent"
/**
 * /api/v1/index.php — Gateway da API do CRM para o Agente Hermes
 * ─────────────────────────────────────────────────────────────────
 * Todos os endpoints passam por aqui.
 * URL: https://crm.formenstore.com.br/api/v1/{endpoint}
 *
 * Endpoints:
 *   GET  /consultar_cliente   ?telefone= ou ?nome=
 *   GET  /consultar_saldo     ?telefone=
 *   POST /aplicar_cashback    {telefone, valor_compra, percentual?}
 *   POST /registrar_venda     {telefone, produto_nome?, valor, forma_pagamento?, observacao?}
 *   POST /cadastrar_produto   {nome, preco, custo?, categoria?, descricao?, tags?, estoque?, sizes?, status?}
 *   GET  /health
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo = get_pdo();

/* ── Health check — sem autenticação ── */
$path     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$endpoint = basename($path, '.php');

if ($endpoint === 'health' || $endpoint === 'v1' || $endpoint === 'index') {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        echo json_encode(['ok' => true, 'status' => 'online', 'clientes' => (int)$st]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'status' => 'db_error', 'error' => $e->getMessage()]);
    }
    exit;
}

/* Autenticacao por token */
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? ($headers['Authorization'] ?? '')
           ?? ($headers['authorization'] ?? '');
$tokenHeader = trim(preg_replace('/^Bearer\s+/i', '', $authHeader));

$tokenQuery = trim((string)($_GET['token'] ?? ''));
$tokenApiHeader = trim((string)(
    $_SERVER['HTTP_X_API_TOKEN']
    ?? ($headers['X-Api-Token'] ?? '')
    ?? ($headers['x-api-token'] ?? '')
));
$token = $tokenHeader ?: ($tokenApiHeader ?: $tokenQuery);

$expected = defined('HERMES_API_TOKEN') ? HERMES_API_TOKEN : getenv('HERMES_API_TOKEN');

if (!$expected) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Token da API nao configurado']);
    exit;
}

if (!$token || !hash_equals((string)$expected, (string)$token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token invalido']);
    exit;
}

/* ── Roteamento ── */
$method   = $_SERVER['REQUEST_METHOD'];
$body     = json_decode(file_get_contents('php://input'), true) ?? [];

/* ══════════════════════════════════════════════════════════════
   HELPER: descobre company_id pelo token (multi-empresa)
   Por hora usa a primeira empresa
══════════════════════════════════════════════════════════════ */
function get_company_id(PDO $pdo): int {
    // Se houver company no token, usar; por hora pega a primeira
    $r = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetch();
    return $r ? (int)$r['id'] : 1;
}

function normalizar_telefone(string $tel): string {
    $d = preg_replace('/\D/', '', $tel);
    if (strlen($d) <= 11 && !str_starts_with($d, '55')) $d = '55' . $d;
    return $d;
}

function ensure_cashback_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cashback_saldos (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT NOT NULL,
        saldo DECIMAL(10,2) NOT NULL DEFAULT 0, updated_at DATETIME DEFAULT NOW() ON UPDATE NOW(),
        UNIQUE KEY uq (company_id,client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cashback_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, client_id INT,
        chave_nfe VARCHAR(60), valor_compra DECIMAL(10,2), percentual DECIMAL(5,2),
        cashback_gerado DECIMAL(10,2), telefone_usado VARCHAR(20), data_compra DATETIME,
        status VARCHAR(20) DEFAULT 'pendente', created_at DATETIME DEFAULT NOW(),
        INDEX idx_client(client_id), INDEX idx_company(company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function buscar_cliente(PDO $pdo, int $cid, string $tel = '', string $nome = ''): ?array {
    if ($tel) {
        $telefone = normalizar_telefone($tel);
        $s8 = substr(preg_replace('/\D/','',$tel), -8);
        $st = $pdo->prepare("SELECT id, nome, whatsapp AS telefone, email FROM clients
            WHERE company_id=?
              AND (REGEXP_REPLACE(whatsapp,'[^0-9]','') = ? OR REGEXP_REPLACE(whatsapp,'[^0-9]','') = ?
                   OR RIGHT(REGEXP_REPLACE(whatsapp,'[^0-9]',''),8) = ?)
            LIMIT 1");
        $st->execute([$cid, $telefone, substr($telefone,2), $s8]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    if ($nome) {
        $st = $pdo->prepare("SELECT id, nome, whatsapp AS telefone, email FROM clients
            WHERE company_id=? AND nome LIKE ? LIMIT 3");
        $st->execute([$cid, '%' . trim($nome) . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : ($rows ?: null);
    }
    return null;
}

$cid = get_company_id($pdo);

/* ══════════════════════════════════════════════════════════════
   GET /consultar_cliente
══════════════════════════════════════════════════════════════ */
if ($endpoint === 'consultar_cliente' && $method === 'GET') {
    $tel  = trim($_GET['telefone'] ?? '');
    $nome = trim($_GET['nome']     ?? '');

    if (!$tel && !$nome) {
        echo json_encode(['ok'=>false,'error'=>'Informe telefone ou nome']); exit;
    }

    $cliente = buscar_cliente($pdo, $cid, $tel, $nome);

    if (!$cliente) {
        echo json_encode(['ok'=>false,'found'=>false,'message'=>'Cliente não encontrado']); exit;
    }

    // Se retornou múltiplos (busca por nome)
    if (isset($cliente[0])) {
        echo json_encode(['ok'=>true,'found'=>true,'multiplos'=>true,'clientes'=>$cliente]); exit;
    }

    // Busca saldo cashback
    $saldo = 0;
    try {
        $ss = $pdo->prepare("SELECT saldo FROM cashback_saldos WHERE company_id=? AND client_id=?");
        $ss->execute([$cid, $cliente['id']]);
        $saldo = (float)($ss->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'      => true,
        'found'   => true,
        'cliente' => array_merge($cliente, ['cashback_saldo' => $saldo])
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   GET /consultar_saldo
══════════════════════════════════════════════════════════════ */
if ($endpoint === 'consultar_saldo' && $method === 'GET') {
    $tel = trim($_GET['telefone'] ?? '');
    if (!$tel) { echo json_encode(['ok'=>false,'error'=>'Telefone obrigatório']); exit; }

    $cliente = buscar_cliente($pdo, $cid, $tel);
    if (!$cliente) {
        echo json_encode(['ok'=>false,'found'=>false,'message'=>'Cliente não encontrado']); exit;
    }

    $saldo = 0;
    try {
        $ss = $pdo->prepare("SELECT saldo FROM cashback_saldos WHERE company_id=? AND client_id=?");
        $ss->execute([$cid, $cliente['id']]);
        $saldo = (float)($ss->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'      => true,
        'cliente' => $cliente['nome'],
        'saldo'   => $saldo,
        'saldo_fmt' => 'R$ ' . number_format($saldo, 2, ',', '.')
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   POST /aplicar_cashback
══════════════════════════════════════════════════════════════ */
if ($endpoint === 'aplicar_cashback' && $method === 'POST') {
    $tel         = trim($body['telefone']     ?? '');
    $valorCompra = (float)($body['valor_compra'] ?? 0);
    $pct         = (float)($body['percentual']   ?? 5);

    if (!$tel || $valorCompra <= 0) {
        echo json_encode(['ok'=>false,'error'=>'telefone e valor_compra obrigatórios']); exit;
    }

    $cliente = buscar_cliente($pdo, $cid, $tel);
    if (!$cliente) {
        echo json_encode(['ok'=>false,'found'=>false,'message'=>'Cliente não encontrado. Cadastre primeiro.']); exit;
    }

    $cashback = round($valorCompra * $pct / 100, 2);
    $expira   = date('Y-m-d', strtotime('+45 days'));

    try {
        ensure_cashback_tables($pdo);

        // Credita
        $pdo->prepare("INSERT INTO cashback_saldos (company_id,client_id,saldo,updated_at) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE saldo=saldo+VALUES(saldo), updated_at=NOW()")
            ->execute([$cid, $cliente['id'], $cashback]);

        $pdo->prepare("INSERT INTO cashback_transactions
            (company_id,client_id,valor_compra,percentual,cashback_gerado,telefone_usado,data_compra,status,created_at)
            VALUES (?,?,?,?,?,?,NOW(),'confirmado',NOW())")
            ->execute([$cid,$cliente['id'],$valorCompra,$pct,$cashback,$tel]);

        // Saldo atualizado
        $ss = $pdo->prepare("SELECT saldo FROM cashback_saldos WHERE company_id=? AND client_id=?");
        $ss->execute([$cid, $cliente['id']]);
        $saldoTotal = (float)($ss->fetchColumn() ?: $cashback);

        echo json_encode([
            'ok'              => true,
            'cliente'         => $cliente['nome'],
            'cashback_gerado' => number_format($cashback, 2, '.', ''),
            'saldo_total'     => number_format($saldoTotal, 2, '.', ''),
            'expira_em'       => $expira,
        ]);

    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   POST /registrar_venda
══════════════════════════════════════════════════════════════ */
if ($endpoint === 'registrar_venda' && $method === 'POST') {
    $tel     = trim($body['telefone']        ?? '');
    $valor   = (float)($body['valor']           ?? 0);
    $produto = trim($body['produto_nome']     ?? 'Venda registrada via agente');
    $pgto    = trim($body['forma_pagamento'] ?? 'nao_informado');
    $obs     = trim($body['observacao']       ?? '');

    if (!$tel || $valor <= 0) {
        echo json_encode(['ok'=>false,'error'=>'telefone e valor obrigatórios']); exit;
    }

    $cliente = buscar_cliente($pdo, $cid, $tel);
    if (!$cliente) {
        echo json_encode(['ok'=>false,'found'=>false,'message'=>'Cliente não encontrado']); exit;
    }

    try {
        ensure_cashback_tables($pdo);
        $pdo->beginTransaction();

        // Registra pedido
        $pdo->prepare("INSERT INTO orders (company_id,client_id,total,forma_pagamento,origem,status,observacoes,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$cid,$cliente['id'],$valor,$pgto,'agente','concluido',$obs]);
        $orderId = (int)$pdo->lastInsertId();

        // Aplica cashback automaticamente
        $cashback = round($valor * 5 / 100, 2);
        $pdo->prepare("INSERT INTO cashback_saldos (company_id,client_id,saldo) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE saldo=saldo+VALUES(saldo), updated_at=NOW()")
            ->execute([$cid,$cliente['id'],$cashback]);

        $pdo->prepare("INSERT INTO cashback_transactions
            (company_id,client_id,valor_compra,percentual,cashback_gerado,telefone_usado,data_compra,status,created_at)
            VALUES (?,?,?,?,?,?,NOW(),'confirmado',NOW())")
            ->execute([$cid,$cliente['id'],$valor,5,$cashback,$tel]);

        $pdo->commit();

        echo json_encode([
            'ok'=>true,
            'order_id'=>$orderId,
            'cliente'=>$cliente['nome'],
            'valor'=>$valor,
            'cashback_gerado'=>number_format($cashback, 2, '.', '')
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   POST /cadastrar_produto
══════════════════════════════════════════════════════════════ */
if ($endpoint === 'cadastrar_produto' && $method === 'POST') {
    $nome      = trim($body['nome']      ?? '');
    $preco     = (float)($body['preco']     ?? 0);
    $custo     = (float)($body['custo']     ?? 0);
    $categoria = trim($body['categoria'] ?? '');
    $descricao = trim($body['descricao'] ?? '');
    $tags      = trim($body['tags']      ?? '');
    $estoque   = (int)($body['estoque']   ?? 1);
    $sizes     = trim($body['sizes']     ?? '');
    $status    = in_array($body['status']??'', ['ativo','rascunho']) ? $body['status'] : 'rascunho';
    $ativo     = $status === 'ativo' ? 1 : 0;

    if (!$nome || $preco <= 0) {
        echo json_encode(['ok'=>false,'error'=>'nome e preco obrigatórios']); exit;
    }

    try {
        // Verifica colunas disponíveis
        $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);

        $fields = ['company_id','nome','preco','categoria','descricao','ativo','created_at'];
        $vals   = [$cid,$nome,$preco,$categoria,$descricao,$ativo,gmdate('Y-m-d H:i:s')];

        if (in_array('custo',$cols)   && $custo)    { $fields[]='custo';    $vals[]=$custo; }
        if (in_array('tags',$cols)    && $tags)     { $fields[]='tags';     $vals[]=$tags; }
        if (in_array('estoque',$cols) && $estoque)  { $fields[]='estoque';  $vals[]=$estoque; }
        if (in_array('sizes',$cols)   && $sizes)    { $fields[]='sizes';    $vals[]=$sizes; }

        $ph = implode(',', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO products (" . implode(',',$fields) . ") VALUES ($ph)")
            ->execute($vals);

        $pid = (int)$pdo->lastInsertId();

        echo json_encode([
            'ok'       => true,
            'produto'  => $nome,
            'id'       => $pid,
            'preco'    => $preco,
            'status'   => $status,
            'message'  => $status === 'rascunho'
                ? "Produto salvo como rascunho. Acesse o CRM para revisar e publicar."
                : "Produto publicado no catálogo!"
        ]);

    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ── 404 ── */
http_response_code(404);
echo json_encode(['ok'=>false,'error'=>"Endpoint '{$endpoint}' não encontrado"]);
