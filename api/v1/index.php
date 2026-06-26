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
 *   GET  /consultar_saldo     ?telefone= ou ?nome=
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

function get_or_create_club_wallet(PDO $pdo, int $cid, int $clientId): array {
    $s = $pdo->prepare("SELECT * FROM club_wallets WHERE company_id=? AND client_id=? LIMIT 1");
    $s->execute([$cid, $clientId]);
    $wallet = $s->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) {
        $pdo->prepare("INSERT INTO club_wallets (company_id,client_id) VALUES (?,?)")->execute([$cid, $clientId]);
        $id = (int)$pdo->lastInsertId();
        $wallet = [
            'id' => $id,
            'company_id' => $cid,
            'client_id' => $clientId,
            'saldo' => 0,
            'total_ganho' => 0,
            'total_resgatado' => 0,
        ];
    }
    return $wallet;
}

function get_club_rules(PDO $pdo, int $cid): array {
    try {
        $s = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
        $s->execute([$cid]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_cashback_saldo(PDO $pdo, int $cid, int $clientId): float {
    try {
        $wallet = get_or_create_club_wallet($pdo, $cid, $clientId);
        return (float)($wallet['saldo'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
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

    $saldo = get_cashback_saldo($pdo, $cid, (int)$cliente['id']);

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
    $tel  = trim($_GET['telefone'] ?? '');
    $nome = trim($_GET['nome']     ?? '');
    if (!$tel && !$nome) { echo json_encode(['ok'=>false,'error'=>'Informe telefone ou nome']); exit; }

    $cliente = buscar_cliente($pdo, $cid, $tel, $nome);
    if (!$cliente) {
        echo json_encode(['ok'=>false,'found'=>false,'message'=>'Cliente não encontrado']); exit;
    }
    if (isset($cliente[0])) {
        echo json_encode(['ok'=>true,'found'=>true,'multiplos'=>true,'clientes'=>$cliente]); exit;
    }

    $saldo = get_cashback_saldo($pdo, $cid, (int)$cliente['id']);

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
    $rules       = get_club_rules($pdo, $cid);
    $pct         = isset($body['percentual']) ? (float)$body['percentual'] : (float)($rules['cashback_pct'] ?? 5);

    if (!$tel || $valorCompra <= 0) {
        echo json_encode(['ok'=>false,'error'=>'telefone e valor_compra obrigatórios']); exit;
    }

    $cliente = buscar_cliente($pdo, $cid, $tel);
    if (!$cliente) {
        echo json_encode(['ok'=>false,'found'=>false,'message'=>'Cliente não encontrado. Cadastre primeiro.']); exit;
    }

    $cashback = round($valorCompra * $pct / 100, 2);
    $validade = (int)($rules['cashback_validade'] ?? 45);
    $expira   = date('Y-m-d', strtotime('+' . $validade . ' days'));

    try {
        $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE club_wallets SET saldo=saldo+?, total_ganho=total_ganho+?, updated_at=NOW() WHERE id=?")
            ->execute([$cashback, $cashback, $wallet['id']]);

        $pdo->prepare("INSERT INTO club_transactions
            (company_id,client_id,wallet_id,tipo,valor,descricao,referencia_tipo,expira_em)
            VALUES (?,?,?,'credito',?,?,?,?)")
            ->execute([$cid, $cliente['id'], $wallet['id'], $cashback, 'Cashback manual via Hermes de R$ ' . number_format($valorCompra, 2, ',', '.'), 'hermes', $expira]);

        $pdo->commit();

        $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);
        $saldoTotal = (float)($wallet['saldo'] ?? 0);

        echo json_encode([
            'ok'              => true,
            'cliente'         => $cliente['nome'],
            'cashback_gerado' => number_format($cashback, 2, '.', ''),
            'saldo_total'     => number_format($saldoTotal, 2, '.', ''),
            'percentual'      => number_format($pct, 2, '.', ''),
            'expira_em'       => $expira,
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
        $rules = get_club_rules($pdo, $cid);
        $pct = (float)($rules['cashback_pct'] ?? 5);
        $validade = (int)($rules['cashback_validade'] ?? 45);
        $expira = date('Y-m-d', strtotime('+' . $validade . ' days'));
        $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);
        $pdo->beginTransaction();

        // Registra pedido
        $pdo->prepare("INSERT INTO orders (company_id,client_id,total,forma_pagamento,origem,status,observacoes,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$cid,$cliente['id'],$valor,$pgto,'agente','concluido',$obs]);
        $orderId = (int)$pdo->lastInsertId();

        // Aplica cashback automaticamente
        $cashback = round($valor * $pct / 100, 2);
        $pdo->prepare("UPDATE club_wallets SET saldo=saldo+?, total_ganho=total_ganho+?, updated_at=NOW() WHERE id=?")
            ->execute([$cashback, $cashback, $wallet['id']]);

        $pdo->prepare("INSERT INTO club_transactions
            (company_id,client_id,wallet_id,tipo,valor,descricao,referencia_tipo,referencia_id,expira_em)
            VALUES (?,?,?,'credito',?,?,?,?,?)")
            ->execute([$cid, $cliente['id'], $wallet['id'], $cashback, 'Cashback de venda via Hermes de R$ ' . number_format($valor, 2, ',', '.'), 'order', $orderId, $expira]);

        $pdo->commit();

        $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);

        echo json_encode([
            'ok'=>true,
            'order_id'=>$orderId,
            'cliente'=>$cliente['nome'],
            'valor'=>$valor,
            'cashback_gerado'=>number_format($cashback, 2, '.', ''),
            'saldo_total'=>number_format((float)($wallet['saldo'] ?? 0), 2, '.', '')
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
