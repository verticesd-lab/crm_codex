<?php
ob_start();
/**
 * /api/v1/index.php — Gateway da API do CRM For Men Store
 * ═══════════════════════════════════════════════════════════════════════
 * Hermes v2 — endpoints completos para gestão de produtos, estoque,
 * cliente e cashback, com auditoria em agent_actions.
 *
 * URL base: https://crm.formenstore.com.br/api/v1/{endpoint}
 *
 * Autenticação:
 *   Authorization: Bearer <HERMES_API_TOKEN>
 *   (ou X-Api-Token: <token>, ou ?token=<token>)
 *
 * Endpoints:
 *   GET  /health                       sem auth — status do banco
 *
 *   ─── Cliente ───
 *   GET  /consultar_cliente            ?telefone= ou ?nome=
 *   GET  /consultar_saldo              ?telefone= ou ?nome=
 *   POST /cadastrar_cliente            {nome, telefone, email?, data_nascimento?}
 *
 *   ─── Cashback / Venda ───
 *   POST /aplicar_cashback             {telefone, valor_compra, percentual?}
 *   POST /registrar_venda              {telefone, valor, produto_nome?, forma_pagamento?, observacao?}
 *
 *   ─── Produto ───
 *   GET  /buscar_produto               ?q= (busca em nome, ref, cor, marca)
 *   POST /cadastrar_produto            {nome, preco, custo?, categoria?, marca?, cor?, referencia?,
 *                                       sizes?, estoque_inicial?, imagens?, status?, agent_meta?}
 *   POST /atualizar_produto            {id, ...campos a alterar}
 *
 *   ─── Estoque ───
 *   POST /atualizar_estoque            {produto_id|variante_id, tamanho?, delta, motivo?, tipo?}
 *
 *   ─── Imagem ───
 *   POST /upload_imagem_produto        multipart: imagem=<file>
 *
 *   ─── NF-e ───
 *   POST /processar_nf_xml             multipart: xml=<file>  OU  {xml_base64}
 *
 * Em TODOS os endpoints, opcionalmente envie no payload:
 *   "agent_meta": {
 *     "telegram_chat_id": 123, "telegram_user_id": 456, "telegram_message_id": 789,
 *     "model_used": "llama-3.3-70b", "duration_ms": 1234
 *   }
 * Isso vai pra agent_actions e ajuda na auditoria.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers.php';
require_once __DIR__ . '/../../products_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$pdo  = get_pdo();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$endpoint = basename($path, '.php');
$method   = $_SERVER['REQUEST_METHOD'];

// Tempo de start pra calcular duration_ms
$reqStart = microtime(true);

// ────────────────────────────────────────────────────────────────────────
// Health check (sem auth)
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'health' || $endpoint === 'v1' || $endpoint === 'index') {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        echo json_encode([
            'ok' => true,
            'status' => 'online',
            'clientes' => (int)$st,
            'version' => 'hermes-v2',
            'time' => date('c'),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'status' => 'db_error', 'error' => $e->getMessage()]);
    }
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// Autenticação
// ────────────────────────────────────────────────────────────────────────
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? ($headers['Authorization'] ?? '')
           ?? ($headers['authorization'] ?? '');
$tokenHeader = trim(preg_replace('/^Bearer\s+/i', '', (string)$authHeader));

$tokenApiHeader = trim((string)(
    $_SERVER['HTTP_X_API_TOKEN']
    ?? ($headers['X-Api-Token'] ?? '')
    ?? ($headers['x-api-token'] ?? '')
));
$tokenQuery = trim((string)($_GET['token'] ?? ''));

$token = $tokenHeader ?: ($tokenApiHeader ?: $tokenQuery);
$expected = defined('HERMES_API_TOKEN') ? HERMES_API_TOKEN : getenv('HERMES_API_TOKEN');

if (!$expected) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Token da API nao configurado no servidor']);
    exit;
}
if (!$token || !hash_equals((string)$expected, (string)$token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Token invalido']);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// Body parsing
// ────────────────────────────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody, true) ?: [];

// agent_meta é opcional — extrai antes pra usar no log
$agentMeta = is_array($body['agent_meta'] ?? null) ? $body['agent_meta'] : [];
unset($body['agent_meta']);

// ────────────────────────────────────────────────────────────────────────
// Helpers locais
// ────────────────────────────────────────────────────────────────────────
function get_company_id(PDO $pdo): int {
    $r = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetch();
    return $r ? (int)$r['id'] : 1;
}

function get_or_create_club_wallet(PDO $pdo, int $cid, int $clientId): array {
    $s = $pdo->prepare("SELECT * FROM club_wallets WHERE company_id=? AND client_id=? LIMIT 1");
    $s->execute([$cid, $clientId]);
    $wallet = $s->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) {
        $pdo->prepare("INSERT INTO club_wallets (company_id,client_id) VALUES (?,?)")->execute([$cid, $clientId]);
        $id = (int)$pdo->lastInsertId();
        $wallet = ['id'=>$id,'company_id'=>$cid,'client_id'=>$clientId,'saldo'=>0,'total_ganho'=>0,'total_resgatado'=>0];
    }
    return $wallet;
}

function get_club_rules(PDO $pdo, int $cid): array {
    try {
        $s = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
        $s->execute([$cid]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/**
 * Sanity check de preço — evita erro de transcrição de áudio
 * ("oitocentos" virando "oito mil")
 */
function preco_sane(float $preco, float $custo = 0): array {
    if ($preco <= 0)        return [false, 'Preço precisa ser maior que zero'];
    if ($preco > 100000)    return [false, 'Preço acima de R$ 100.000 — confirme com o usuário'];
    if ($custo > 0 && $preco < $custo) return [false, 'Preço de venda menor que o custo — confirme'];
    if ($custo > 0 && $preco > $custo * 20) return [true, 'AVISO: markup acima de 20x — confirme se está correto'];
    return [true, null];
}

/**
 * Wrapper que loga toda chamada em agent_actions.
 * Use no fim de cada endpoint:
 *   finish_request($pdo, $cid, $endpoint, $body, $response, $reqStart, $agentMeta, $httpStatus);
 */
function finish_request(
    PDO $pdo, int $cid, string $endpoint, array $payload, array $response,
    float $reqStart, array $agentMeta, int $httpStatus = 200
): void {
    $duration = (int)round((microtime(true) - $reqStart) * 1000);
    hermes_log_action($pdo, [
        'company_id'         => $cid,
        'tool_name'          => $endpoint,
        'endpoint'           => '/api/v1/' . $endpoint,
        'payload'            => $payload,
        'response'           => $response,
        'http_status'        => $httpStatus,
        'success'            => !empty($response['ok']),
        'error_message'      => $response['error'] ?? null,
        'duration_ms'        => $duration,
        'model_used'         => $agentMeta['model_used'] ?? null,
        'telegram_chat_id'   => $agentMeta['telegram_chat_id'] ?? null,
        'telegram_user_id'   => $agentMeta['telegram_user_id'] ?? null,
        'telegram_message_id'=> $agentMeta['telegram_message_id'] ?? null,
    ]);
    if ($httpStatus !== 200) http_response_code($httpStatus);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$cid = get_company_id($pdo);

// ════════════════════════════════════════════════════════════════════════
// ENDPOINTS
// ════════════════════════════════════════════════════════════════════════

// ────────────────────────────────────────────────────────────────────────
// GET /consultar_cliente
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'consultar_cliente' && $method === 'GET') {
    $tel  = trim($_GET['telefone'] ?? '');
    $nome = trim($_GET['nome'] ?? '');
    if (!$tel && !$nome) {
        finish_request($pdo, $cid, $endpoint, $_GET,
            ['ok'=>false,'error'=>'Informe telefone ou nome'], $reqStart, $agentMeta, 400);
    }
    $cliente = hermes_buscar_cliente($pdo, $cid, $tel, $nome);
    if (!$cliente) {
        finish_request($pdo, $cid, $endpoint, $_GET,
            ['ok'=>false,'found'=>false,'message'=>'Cliente nao encontrado'], $reqStart, $agentMeta);
    }
    if (isset($cliente[0])) {
        finish_request($pdo, $cid, $endpoint, $_GET,
            ['ok'=>true,'found'=>true,'multiplos'=>true,'clientes'=>$cliente], $reqStart, $agentMeta);
    }
    $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);
    finish_request($pdo, $cid, $endpoint, $_GET, [
        'ok'      => true,
        'found'   => true,
        'cliente' => array_merge($cliente, ['cashback_saldo' => (float)$wallet['saldo']])
    ], $reqStart, $agentMeta);
}

// ────────────────────────────────────────────────────────────────────────
// GET /consultar_saldo
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'consultar_saldo' && $method === 'GET') {
    $tel  = trim($_GET['telefone'] ?? '');
    $nome = trim($_GET['nome'] ?? '');
    if (!$tel && !$nome) {
        finish_request($pdo, $cid, $endpoint, $_GET,
            ['ok'=>false,'error'=>'Informe telefone ou nome'], $reqStart, $agentMeta, 400);
    }
    $cliente = hermes_buscar_cliente($pdo, $cid, $tel, $nome);
    if (!$cliente) {
        finish_request($pdo, $cid, $endpoint, $_GET,
            ['ok'=>false,'found'=>false,'message'=>'Cliente nao encontrado'], $reqStart, $agentMeta);
    }
    if (isset($cliente[0])) {
        finish_request($pdo, $cid, $endpoint, $_GET,
            ['ok'=>true,'found'=>true,'multiplos'=>true,'clientes'=>$cliente], $reqStart, $agentMeta);
    }
    $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);
    $saldo  = (float)($wallet['saldo'] ?? 0);
    finish_request($pdo, $cid, $endpoint, $_GET, [
        'ok'        => true,
        'cliente'   => $cliente['nome'],
        'saldo'     => $saldo,
        'saldo_fmt' => 'R$ ' . number_format($saldo, 2, ',', '.')
    ], $reqStart, $agentMeta);
}

// ────────────────────────────────────────────────────────────────────────
// POST /cadastrar_cliente
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'cadastrar_cliente' && $method === 'POST') {
    $nome  = trim($body['nome']             ?? '');
    $tel   = trim($body['telefone']         ?? '');
    $email = trim($body['email']            ?? '');
    $nasc  = trim($body['data_nascimento']  ?? '');

    if ($nome === '' || $tel === '') {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'nome e telefone obrigatorios'], $reqStart, $agentMeta, 400);
    }

    // Detecta duplicidade por telefone
    $dup = hermes_buscar_cliente($pdo, $cid, $tel);
    if ($dup && !isset($dup[0])) {
        finish_request($pdo, $cid, $endpoint, $body, [
            'ok'         => false,
            'duplicado'  => true,
            'cliente_id' => (int)$dup['id'],
            'message'    => "Ja existe cliente com este telefone: " . $dup['nome']
        ], $reqStart, $agentMeta);
    }

    // Normaliza telefone com 55
    $digits = preg_replace('/\D/', '', $tel);
    if (strlen($digits) <= 11 && substr($digits, 0, 2) !== '55') $digits = '55' . $digits;

    try {
        $stmt = $pdo->prepare('
            INSERT INTO clients (company_id, nome, whatsapp, telefone_principal, email, data_nascimento, origem, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())
        ');
        $stmt->execute([
            $cid, $nome, $digits, $digits,
            $email ?: null,
            $nasc ?: null,
            'hermes'
        ]);
        $clientId = (int)$pdo->lastInsertId();

        // Cria wallet de cashback automaticamente
        get_or_create_club_wallet($pdo, $cid, $clientId);

        finish_request($pdo, $cid, $endpoint, $body, [
            'ok'         => true,
            'cliente_id' => $clientId,
            'nome'       => $nome,
            'telefone'   => $digits,
            'message'    => 'Cliente cadastrado e carteira de cashback criada'
        ], $reqStart, $agentMeta);
    } catch (Throwable $e) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'Erro ao cadastrar: ' . $e->getMessage()],
            $reqStart, $agentMeta, 500);
    }
}

// ────────────────────────────────────────────────────────────────────────
// POST /aplicar_cashback
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'aplicar_cashback' && $method === 'POST') {
    $tel         = trim($body['telefone'] ?? '');
    $valorCompra = (float)($body['valor_compra'] ?? 0);
    $rules       = get_club_rules($pdo, $cid);
    $pct         = isset($body['percentual']) ? (float)$body['percentual'] : (float)($rules['cashback_pct'] ?? 5);

    if (!$tel || $valorCompra <= 0) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'telefone e valor_compra obrigatorios'], $reqStart, $agentMeta, 400);
    }

    $cliente = hermes_buscar_cliente($pdo, $cid, $tel);
    if (!$cliente || isset($cliente[0])) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'found'=>false,'message'=>'Cliente nao encontrado. Cadastre primeiro.'],
            $reqStart, $agentMeta);
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
            ->execute([
                $cid, $cliente['id'], $wallet['id'], $cashback,
                'Cashback manual via Hermes de R$ ' . number_format($valorCompra, 2, ',', '.'),
                'hermes', $expira
            ]);

        $pdo->commit();

        $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);
        finish_request($pdo, $cid, $endpoint, $body, [
            'ok'              => true,
            'cliente'         => $cliente['nome'],
            'cashback_gerado' => number_format($cashback, 2, '.', ''),
            'saldo_total'     => number_format((float)$wallet['saldo'], 2, '.', ''),
            'percentual'      => number_format($pct, 2, '.', ''),
            'expira_em'       => $expira,
        ], $reqStart, $agentMeta);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>$e->getMessage()],
            $reqStart, $agentMeta, 500);
    }
}

// ────────────────────────────────────────────────────────────────────────
// POST /registrar_venda
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'registrar_venda' && $method === 'POST') {
    $tel     = trim($body['telefone']        ?? '');
    $valor   = (float)($body['valor']        ?? 0);
    $produto = trim($body['produto_nome']    ?? 'Venda registrada via Hermes');
    $pgto    = trim($body['forma_pagamento'] ?? 'nao_informado');
    $obs     = trim($body['observacao']      ?? '');

    if (!$tel || $valor <= 0) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'telefone e valor obrigatorios'], $reqStart, $agentMeta, 400);
    }

    $cliente = hermes_buscar_cliente($pdo, $cid, $tel);
    if (!$cliente || isset($cliente[0])) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'found'=>false,'message'=>'Cliente nao encontrado'],
            $reqStart, $agentMeta);
    }

    try {
        $rules = get_club_rules($pdo, $cid);
        $pct = (float)($rules['cashback_pct'] ?? 5);
        $validade = (int)($rules['cashback_validade'] ?? 45);
        $expira = date('Y-m-d', strtotime('+' . $validade . ' days'));
        $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO orders
            (company_id,client_id,total,forma_pagamento,origem,status,observacoes,created_at)
            VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$cid,$cliente['id'],$valor,$pgto,'agente','concluido',$obs]);
        $orderId = (int)$pdo->lastInsertId();

        $cashback = round($valor * $pct / 100, 2);
        $pdo->prepare("UPDATE club_wallets SET saldo=saldo+?, total_ganho=total_ganho+?, updated_at=NOW() WHERE id=?")
            ->execute([$cashback, $cashback, $wallet['id']]);

        $pdo->prepare("INSERT INTO club_transactions
            (company_id,client_id,wallet_id,tipo,valor,descricao,referencia_tipo,referencia_id,expira_em)
            VALUES (?,?,?,'credito',?,?,?,?,?)")
            ->execute([
                $cid, $cliente['id'], $wallet['id'], $cashback,
                'Cashback de venda via Hermes de R$ ' . number_format($valor, 2, ',', '.'),
                'order', $orderId, $expira
            ]);

        $pdo->commit();

        $wallet = get_or_create_club_wallet($pdo, $cid, (int)$cliente['id']);
        finish_request($pdo, $cid, $endpoint, $body, [
            'ok'              => true,
            'order_id'        => $orderId,
            'cliente'         => $cliente['nome'],
            'valor'           => $valor,
            'cashback_gerado' => number_format($cashback, 2, '.', ''),
            'saldo_total'     => number_format((float)$wallet['saldo'], 2, '.', '')
        ], $reqStart, $agentMeta);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>$e->getMessage()],
            $reqStart, $agentMeta, 500);
    }
}

// ────────────────────────────────────────────────────────────────────────
// GET /buscar_produto
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'buscar_produto' && $method === 'GET') {
    $q     = trim($_GET['q'] ?? '');
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));

    if ($q === '') {
        finish_request($pdo, $cid, $endpoint, $_GET,
            ['ok'=>false,'error'=>'Informe ?q='], $reqStart, $agentMeta, 400);
    }

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.nome, p.referencia, p.cor, p.marca, p.categoria,
            p.preco, p.preco_custo, p.estoque, p.ativo, p.em_oferta,
            p.imagem,
            (SELECT COALESCE(SUM(sb.quantity),0)
             FROM product_variants pv
             JOIN stock_balances sb ON sb.product_variant_id = pv.id
             WHERE pv.product_id = p.id AND pv.active = 1) AS estoque_total_variantes,
            (SELECT GROUP_CONCAT(CONCAT(pv.size, ':', COALESCE(sb.quantity,0)) ORDER BY pv.size SEPARATOR ',')
             FROM product_variants pv
             LEFT JOIN stock_balances sb ON sb.product_variant_id = pv.id
             WHERE pv.product_id = p.id AND pv.active = 1) AS variantes
        FROM products p
        WHERE p.company_id = ?
          AND (
            p.nome       LIKE ?
            OR p.referencia LIKE ?
            OR p.cor        LIKE ?
            OR p.marca      LIKE ?
            OR p.categoria  LIKE ?
          )
        ORDER BY p.ativo DESC, p.updated_at DESC
        LIMIT $limit
    ");
    $stmt->execute([$cid, $like, $like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    finish_request($pdo, $cid, $endpoint, $_GET, [
        'ok'       => true,
        'q'        => $q,
        'total'    => count($rows),
        'produtos' => $rows
    ], $reqStart, $agentMeta);
}

// ────────────────────────────────────────────────────────────────────────
// POST /cadastrar_produto
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'cadastrar_produto' && $method === 'POST') {
    $nome       = trim($body['nome']       ?? '');
    $preco      = (float)($body['preco']   ?? 0);
    $custo      = isset($body['custo']) ? (float)$body['custo'] : 0;
    $categoria  = trim($body['categoria']  ?? '');
    $marca      = trim($body['marca']      ?? '');
    $cor        = trim($body['cor']        ?? '');
    $referencia = trim($body['referencia'] ?? '');
    $descricao  = trim($body['descricao']  ?? '');
    $sizes      = trim($body['sizes']      ?? '');
    $estoqueIni = max(0, (int)($body['estoque_inicial'] ?? 0));
    $imagens    = is_array($body['imagens'] ?? null) ? array_slice($body['imagens'], 0, 4) : [];
    $statusReq  = $body['status'] ?? 'rascunho';
    $status     = in_array($statusReq, ['ativo','rascunho'], true) ? $statusReq : 'rascunho';
    $ativo      = $status === 'ativo' ? 1 : 0;
    $confirmDup = !empty($body['confirmar_duplicado']);

    if (!$nome || $preco <= 0) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'nome e preco obrigatorios'], $reqStart, $agentMeta, 400);
    }

    // Sanity check de preço
    [$sane, $warn] = preco_sane($preco, $custo);
    if (!$sane) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>$warn], $reqStart, $agentMeta, 400);
    }

    // Detecção de duplicidade
    if (!$confirmDup) {
        $dup = hermes_find_duplicate_product($pdo, $cid, $nome, $referencia, $cor);
        if ($dup) {
            finish_request($pdo, $cid, $endpoint, $body, [
                'ok'         => false,
                'duplicado'  => true,
                'produto_existente' => [
                    'id'         => (int)$dup['id'],
                    'nome'       => $dup['nome'],
                    'referencia' => $dup['referencia'],
                    'cor'        => $dup['cor'],
                    'preco'      => (float)$dup['preco'],
                    'estoque'    => (int)$dup['estoque'],
                ],
                'message' => 'Produto parecido ja existe. Reenvie com "confirmar_duplicado": true para cadastrar mesmo assim, ou use /atualizar_estoque para adicionar peças ao existente.'
            ], $reqStart, $agentMeta);
        }
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO products
                (company_id, nome, descricao, categoria, marca, colecao,
                 preco, preco_custo, referencia, cor, sizes,
                 imagem, imagem2, imagem3, imagem4,
                 ativo, created_at, updated_at)
            VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?, ?, NOW(), NOW())
        ');
        $stmt->execute([
            $cid, $nome, $descricao, $categoria, $marca ?: null,
            trim($body['colecao'] ?? '') ?: null,
            $preco, $custo ?: null, $referencia ?: null, $cor ?: null, $sizes ?: null,
            $imagens[0] ?? null, $imagens[1] ?? null, $imagens[2] ?? null, $imagens[3] ?? null,
            $ativo
        ]);
        $productId = (int)$pdo->lastInsertId();

        // Variantes (se houver sizes)
        $variantStats = ['created' => 0, 'kept' => 0, 'deactivated' => 0];
        if ($sizes !== '') {
            $variantStats = hermes_sync_variants($pdo, $productId, $sizes, $cor, $referencia);

            // Se foi informado estoque_inicial, distribui igualmente entre as variantes
            if ($estoqueIni > 0 && $variantStats['created'] > 0) {
                $perVariant = (int)floor($estoqueIni / $variantStats['created']);
                $remainder  = $estoqueIni - ($perVariant * $variantStats['created']);

                $vs = $pdo->prepare('SELECT id FROM product_variants WHERE product_id=? AND active=1 ORDER BY id');
                $vs->execute([$productId]);
                $varIds = $vs->fetchAll(PDO::FETCH_COLUMN);

                foreach ($varIds as $i => $vid) {
                    $qty = $perVariant + ($i < $remainder ? 1 : 0);
                    if ($qty > 0) {
                        $pdo->prepare('UPDATE stock_balances SET quantity=?, updated_at=NOW() WHERE product_variant_id=? AND location="loja_fisica"')
                            ->execute([$qty, $vid]);
                        $pdo->prepare('INSERT INTO stock_movements (product_variant_id, movement_type, quantity, reason, created_at) VALUES (?,"entrada",?,"Cadastro inicial via Hermes",NOW())')
                            ->execute([$vid, $qty]);
                    }
                }
            }
        } elseif ($estoqueIni > 0) {
            // Sem tamanhos — grava direto em products.estoque
            $pdo->prepare('UPDATE products SET estoque=? WHERE id=?')->execute([$estoqueIni, $productId]);
        }

        // Recalcula estoque agregado
        hermes_recalc_product_stock($pdo, $productId);

        $pdo->commit();

        $resp = [
            'ok'        => true,
            'produto'   => $nome,
            'id'        => $productId,
            'preco'     => $preco,
            'status'    => $status,
            'variantes' => $variantStats,
            'message'   => $status === 'rascunho'
                ? 'Produto salvo como rascunho. Acesse o CRM para revisar e ativar.'
                : 'Produto publicado no catalogo!',
        ];
        if ($warn) $resp['warning'] = $warn;
        finish_request($pdo, $cid, $endpoint, $body, $resp, $reqStart, $agentMeta);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'Erro ao cadastrar: ' . $e->getMessage()],
            $reqStart, $agentMeta, 500);
    }
}

// ────────────────────────────────────────────────────────────────────────
// POST /atualizar_produto
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'atualizar_produto' && $method === 'POST') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'id obrigatorio'], $reqStart, $agentMeta, 400);
    }

    $check = $pdo->prepare('SELECT id FROM products WHERE id=? AND company_id=?');
    $check->execute([$id, $cid]);
    if (!$check->fetch()) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'Produto nao encontrado'], $reqStart, $agentMeta, 404);
    }

    $allowed = ['nome','descricao','categoria','marca','colecao','preco','preco_custo',
                'referencia','cor','sizes','desconto','ativo','destaque','em_oferta',
                'preco_oferta','preco_original','oferta_estoque','oferta_validade'];
    $sets = []; $vals = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = "`$field`=?";
            $vals[] = $body[$field];
        }
    }
    if (empty($sets)) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'Nenhum campo para atualizar'], $reqStart, $agentMeta, 400);
    }

    try {
        $vals[] = $id;
        $vals[] = $cid;
        $pdo->prepare('UPDATE products SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=? AND company_id=?')
            ->execute($vals);
        finish_request($pdo, $cid, $endpoint, $body, [
            'ok' => true,
            'id' => $id,
            'campos_atualizados' => array_keys(array_intersect_key($body, array_flip($allowed)))
        ], $reqStart, $agentMeta);
    } catch (Throwable $e) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>$e->getMessage()],
            $reqStart, $agentMeta, 500);
    }
}

// ────────────────────────────────────────────────────────────────────────
// POST /atualizar_estoque
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'atualizar_estoque' && $method === 'POST') {
    $variantId = (int)($body['variante_id'] ?? 0);
    $productId = (int)($body['produto_id']  ?? 0);
    $tamanho   = trim($body['tamanho'] ?? '');
    $delta     = (int)($body['delta']  ?? 0);
    $motivo    = trim($body['motivo']  ?? 'Ajuste via Hermes');
    $tipo      = $body['tipo'] ?? 'ajuste'; // entrada, saida, ajuste, devolucao

    if (!in_array($tipo, ['entrada','saida','ajuste','devolucao'], true)) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'tipo invalido. Use: entrada, saida, ajuste, devolucao'],
            $reqStart, $agentMeta, 400);
    }
    if ($delta === 0) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'delta nao pode ser zero'], $reqStart, $agentMeta, 400);
    }

    // Resolve variante
    if (!$variantId) {
        if (!$productId || $tamanho === '') {
            finish_request($pdo, $cid, $endpoint, $body,
                ['ok'=>false,'error'=>'Informe variante_id OU (produto_id + tamanho)'],
                $reqStart, $agentMeta, 400);
        }
        $stmt = $pdo->prepare('
            SELECT pv.id FROM product_variants pv
            JOIN products p ON p.id = pv.product_id
            WHERE p.company_id=? AND pv.product_id=? AND pv.size=? AND pv.active=1
            LIMIT 1
        ');
        $stmt->execute([$cid, $productId, $tamanho]);
        $variantId = (int)$stmt->fetchColumn();
        if (!$variantId) {
            finish_request($pdo, $cid, $endpoint, $body,
                ['ok'=>false,'error'=>"Variante nao encontrada (produto $productId, tamanho $tamanho)"],
                $reqStart, $agentMeta, 404);
        }
    }

    // Confere se variante pertence à empresa
    $check = $pdo->prepare('
        SELECT pv.id, pv.product_id, pv.size, pv.color
        FROM product_variants pv
        JOIN products p ON p.id = pv.product_id
        WHERE pv.id=? AND p.company_id=?
    ');
    $check->execute([$variantId, $cid]);
    $variant = $check->fetch(PDO::FETCH_ASSOC);
    if (!$variant) {
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>'Variante nao encontrada'], $reqStart, $agentMeta, 404);
    }

    try {
        $pdo->beginTransaction();

        // Saldo atual
        $bal = $pdo->prepare('SELECT id, quantity FROM stock_balances WHERE product_variant_id=? AND location="loja_fisica"');
        $bal->execute([$variantId]);
        $balRow = $bal->fetch(PDO::FETCH_ASSOC);

        $atual = $balRow ? (int)$balRow['quantity'] : 0;
        $novo  = max(0, $atual + $delta);

        if ($balRow) {
            $pdo->prepare('UPDATE stock_balances SET quantity=?, updated_at=NOW() WHERE id=?')
                ->execute([$novo, $balRow['id']]);
        } else {
            $pdo->prepare('INSERT INTO stock_balances (product_variant_id, location, quantity, updated_at) VALUES (?,"loja_fisica",?,NOW())')
                ->execute([$variantId, $novo]);
        }

        // Registra movimento
        $pdo->prepare('INSERT INTO stock_movements (product_variant_id, movement_type, quantity, reason, created_at) VALUES (?,?,?,?,NOW())')
            ->execute([$variantId, $tipo, abs($delta), $motivo]);

        // Recalcula products.estoque
        $totalProd = hermes_recalc_product_stock($pdo, (int)$variant['product_id']);

        $pdo->commit();

        finish_request($pdo, $cid, $endpoint, $body, [
            'ok'           => true,
            'variante_id'  => $variantId,
            'produto_id'   => (int)$variant['product_id'],
            'tamanho'      => $variant['size'],
            'cor'          => $variant['color'],
            'saldo_anterior'=> $atual,
            'saldo_novo'   => $novo,
            'delta'        => $delta,
            'tipo'         => $tipo,
            'total_produto'=> $totalProd,
        ], $reqStart, $agentMeta);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        finish_request($pdo, $cid, $endpoint, $body,
            ['ok'=>false,'error'=>$e->getMessage()],
            $reqStart, $agentMeta, 500);
    }
}

// ────────────────────────────────────────────────────────────────────────
// POST /upload_imagem_produto
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'upload_imagem_produto' && $method === 'POST') {
    if (empty($_FILES['imagem'])) {
        finish_request($pdo, $cid, $endpoint, ['files'=>array_keys($_FILES)],
            ['ok'=>false,'error'=>'Envie a imagem em multipart no campo "imagem"'],
            $reqStart, $agentMeta, 400);
    }
    $path = upload_image_optimized('imagem', 'products');
    if (!$path) {
        finish_request($pdo, $cid, $endpoint, ['name'=>$_FILES['imagem']['name'] ?? ''],
            ['ok'=>false,'error'=>'Falha ao processar imagem. Use JPG/PNG/WebP de ate ' . MAX_UPLOAD_SIZE_MB . 'MB.'],
            $reqStart, $agentMeta, 400);
    }
    finish_request($pdo, $cid, $endpoint, ['name'=>$_FILES['imagem']['name'] ?? ''], [
        'ok'   => true,
        'path' => $path,
        'url'  => image_url($path),
    ], $reqStart, $agentMeta);
}

// ────────────────────────────────────────────────────────────────────────
// POST /processar_nf_xml
// Recebe XML em multipart (xml=<file>) ou base64 (body.xml_base64)
// Conecta no pipeline já existente de products_imports
// ────────────────────────────────────────────────────────────────────────
if ($endpoint === 'processar_nf_xml' && $method === 'POST') {
    $xmlContent = '';
    $origName   = 'nfe_via_hermes.xml';

    if (!empty($_FILES['xml']) && $_FILES['xml']['error'] === UPLOAD_ERR_OK) {
        $xmlContent = (string)@file_get_contents($_FILES['xml']['tmp_name']);
        $origName   = $_FILES['xml']['name'] ?? $origName;
    } elseif (!empty($body['xml_base64'])) {
        $xmlContent = (string)base64_decode($body['xml_base64'], true);
    } elseif (!empty($body['xml'])) {
        $xmlContent = (string)$body['xml'];
    }

    if ($xmlContent === '' || !str_contains($xmlContent, '<')) {
        finish_request($pdo, $cid, $endpoint, ['size'=>strlen($xmlContent)],
            ['ok'=>false,'error'=>'XML vazio ou invalido. Envie como multipart "xml" ou JSON {"xml_base64": "..."}'],
            $reqStart, $agentMeta, 400);
    }

    // Salva o arquivo no mesmo padrão de products_imports.php
    $dir = __DIR__ . '/../../uploads/imports';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME))
              . '_hermes_' . date('Ymd_His') . '.xml';
    $storedPath = 'uploads/imports/' . $safeName;
    file_put_contents($dir . '/' . $safeName, $xmlContent);

    // Cria registro em product_imports
    try {
        $pdo->prepare('INSERT INTO product_imports
            (company_id, original_filename, stored_path, file_ext, status, total_rows)
            VALUES (?,?,?,?,?,?)')
            ->execute([$cid, $origName, $storedPath, 'xml', 'uploaded', 0]);
        $importId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        finish_request($pdo, $cid, $endpoint, ['name'=>$origName],
            ['ok'=>false,'error'=>'Erro ao registrar import: ' . $e->getMessage()],
            $reqStart, $agentMeta, 500);
    }

    // Processa o XML — reusa as funções do products_imports.php
    // (precisa do helper ni_read_xml_nfe + ni_process_rows)
    // Como elas estão no escopo de products_imports.php, replicamos o essencial aqui
    // ou exigimos que o arquivo seja incluído. Mais limpo: include do processador.

    try {
        require_once __DIR__ . '/../../products_imports_processor.php';
        $rows  = ni_read_xml_nfe($dir . '/' . $safeName);
        $total = ni_process_rows($rows, $pdo, $importId, $cid);

        $pdo->prepare('UPDATE product_imports SET status="processed", total_rows=? WHERE id=? AND company_id=?')
            ->execute([$total, $importId, $cid]);

        // Resumo extra: custo total e fornecedor
        $sum = $pdo->prepare('SELECT COUNT(*) AS qt, SUM(preco_custo * quantidade) AS custo_total
                              FROM product_import_items
                              WHERE import_id=? AND company_id=?');
        $sum->execute([$importId, $cid]);
        $sumRow = $sum->fetch(PDO::FETCH_ASSOC);

        $reviewUrl = (defined('BASE_URL') ? BASE_URL : '')
                   . '/products_imports.php?action=view&id=' . $importId;

        finish_request($pdo, $cid, $endpoint, ['name'=>$origName,'size'=>strlen($xmlContent)], [
            'ok'         => true,
            'import_id'  => $importId,
            'total_itens'=> (int)$sumRow['qt'],
            'custo_total'=> (float)$sumRow['custo_total'],
            'review_url' => $reviewUrl,
            'message'    => "Recebi {$sumRow['qt']} itens. Custo total R$ " . number_format((float)$sumRow['custo_total'], 2, ',', '.') . ". Revise e publique no CRM."
        ], $reqStart, $agentMeta);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE product_imports SET status="failed" WHERE id=? AND company_id=?')
            ->execute([$importId, $cid]);
        finish_request($pdo, $cid, $endpoint, ['name'=>$origName],
            ['ok'=>false,'error'=>'Erro ao processar NF: ' . $e->getMessage(),'import_id'=>$importId],
            $reqStart, $agentMeta, 500);
    }
}

// ────────────────────────────────────────────────────────────────────────
// 404
// ────────────────────────────────────────────────────────────────────────
http_response_code(404);
echo json_encode(['ok'=>false,'error'=>"Endpoint '{$endpoint}' nao encontrado"], JSON_UNESCAPED_UNICODE);