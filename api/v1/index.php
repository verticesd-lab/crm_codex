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

if ($endpoint === 'health' || $endpoint === 'v1') {
    $st = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    echo json_encode(['ok' => true, 'status' => 'online', 'clientes' => (int)$st]);
    exit;
}

/* ── Autenticação por Bearer Token ── */
// Tenta pegar token do header Authorization
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? ($headers['Authorization'] ?? '')
           ?? ($headers['authorization'] ?? '');
$tokenHeader = trim(str_replace(['Bearer', 'bearer'], '', $authHeader));

// Fallback: aceita token via query string ?token=... ou header X-Api-Token
$tokenQuery  = trim($_GET['token'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '');
$token       = $tokenHeader ?: $tokenQuery;

$expected = defined('HERMES_API_TOKEN') ? HERMES_API_TOKEN : getenv('HERMES_API_TOKEN');

if ($expected && $token !== $expected) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token inválido', 'debug_token_recebido' => substr($token, 0, 8) . '...']);
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

function normalizar_wa(string $tel): string {
    $d = preg_replace('/\D/', '', $tel);
    if (strlen($d) <= 11 && !str_starts_with($d, '55')) $d = '55' . $d;
    return $d;
}

function buscar_cliente(PDO $pdo, int $cid, string $tel = '', string $nome = ''): ?array {
    if ($tel) {
        $wa = normalizar_wa($tel);
        $s8 = substr(preg_replace('/\D/','',$tel), -8);
        $st = $pdo->prepare("SELECT id, nome, whatsapp, email FROM clients
            WHERE company_id=?
              AND (REGEXP_REPLACE(whatsapp,'[^0-9]','') = ? OR REGEXP_REPLACE(whatsapp,'[^0-9]','') = ?
                   OR RIGHT(REGEXP_REPLACE(whatsapp,'[^0-9]',''),8) = ?)
            LIMIT 1");
        $st->execute([$cid, $wa, substr($wa,2), $s8]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    if ($nome) {
        $st = $pdo->prepare("SELECT id, nome, whatsapp, email FROM clients
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
    $now      = gmdate('Y-m-d H:i:s');

    try {
        // Garante tabelas
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

        // Envia WhatsApp
        $wa_enviado = false;
        try {
            $evoUrl = defined('EVOLUTION_API_URL') ? EVOLUTION_API_URL : getenv('EVOLUTION_API_URL');
            $evoKey = defined('EVOLUTION_API_KEY') ? EVOLUTION_API_KEY : getenv('EVOLUTION_API_KEY');
            $evoIns = defined('EVOLUTION_INSTANCE') ? EVOLUTION_INSTANCE : getenv('EVOLUTION_INSTANCE');
            $nome1  = explode(' ', trim($cliente['nome']))[0];

            if ($evoUrl && $evoKey) {
                $waNum = normalizar_wa($cliente['whatsapp'] ?? $tel);
                $msg   = "Oi, {$nome1}! 🎉\n\nAcabamos de creditar *R$ " . number_format($cashback,2,',','.') . "* de cashback na sua carteira do *Clube For Men*! 💰\n\n💳 Seu saldo total: *R$ " . number_format($saldoTotal,2,',','.') . "*\n⏰ Válido por *45 dias*\n\n_Use na próxima compra!_ 😊";

                $ch = curl_init("{$evoUrl}/message/sendText/{$evoIns}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => ["Content-Type: application/json","apikey: {$evoKey}"],
                    CURLOPT_POSTFIELDS  => json_encode(['number'=>$waNum,'text'=>$msg]),
                    CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false
                ]);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $wa_enviado = $code >= 200 && $code < 300;
            }
        } catch (Throwable $e) {}

        echo json_encode([
            'ok'              => true,
            'cliente'         => $cliente['nome'],
            'cashback_gerado' => number_format($cashback, 2, '.', ''),
            'saldo_total'     => number_format($saldoTotal, 2, '.', ''),
            'whatsapp_enviado'=> $wa_enviado,
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
        // Registra pedido
        $pdo->prepare("INSERT INTO orders (company_id,client_id,total,forma_pagamento,origem,status,observacoes,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$cid,$cliente['id'],$valor,$pgto,'agente','concluido',$obs]);
        $orderId = (int)$pdo->lastInsertId();

        echo json_encode(['ok'=>true,'order_id'=>$orderId,'cliente'=>$cliente['nome'],'valor'=>$valor]);

        // Aplica cashback automaticamente
        $cashback = round($valor * 5 / 100, 2);
        $pdo->prepare("INSERT INTO cashback_saldos (company_id,client_id,saldo) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE saldo=saldo+VALUES(saldo), updated_at=NOW()")
            ->execute([$cid,$cliente['id'],$cashback]);

    } catch (Throwable $e) {
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
