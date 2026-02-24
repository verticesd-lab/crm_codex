<?php
/**
 * webhook_evolution.php
 * Recebe POSTs do ActivePieces → salva interactions no CRM
 *
 * Payload do ActivePieces:
 * {
 *   "data": {
 *     "event": "messages.upsert",
 *     "instance": "loja_oficial",
 *     "_crm_intent": "Agenda Barbearia ✂️",
 *     "_crm_origem": "activepieces",
 *     "data": {
 *       "key": { "remoteJid": "5565...@s.whatsapp.net", "fromMe": false },
 *       "pushName": "Nome do Cliente",
 *       "messageType": "conversation",
 *       "message": { "conversation": "texto" }
 *     }
 *   }
 * }
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// Responde imediatamente (evita timeout no ActivePieces)
http_response_code(200);
header('Content-Type: application/json');

// ── Log de debug (descomente para diagnosticar) ──────────────────
// $logDir = __DIR__ . '/logs';
// if (!is_dir($logDir)) mkdir($logDir, 0775, true);
// file_put_contents($logDir . '/webhook_debug.log',
//     date('Y-m-d H:i:s') . "\n" . file_get_contents('php://input') . "\n\n---\n\n",
//     FILE_APPEND
// );

// ── Lê o payload ────────────────────────────────────────────────
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (empty($payload)) {
    echo json_encode(['ok' => false, 'error' => 'empty_payload']);
    exit;
}

// Suporta { data: {...} } ou o objeto direto
$root = isset($payload['data']) && is_array($payload['data'])
    ? $payload['data']
    : $payload;

$event      = $root['event']       ?? '';
$instance   = $root['instance']    ?? 'loja_oficial';
$crmIntent  = $root['_crm_intent'] ?? null;   // "Agenda Barbearia ✂️" etc
$crmOrigem  = $root['_crm_origem'] ?? 'whatsapp';

$msgData    = $root['data']        ?? [];

$remoteJid  = $msgData['key']['remoteJid'] ?? '';
$fromMe     = (bool)($msgData['key']['fromMe'] ?? false);
$pushName   = trim($msgData['pushName'] ?? '');
$msgType    = $msgData['messageType'] ?? 'conversation';
$msgText    = $msgData['message']['conversation']
           ?? $msgData['message']['extendedTextMessage']['text']
           ?? '';

// ── Ignora mensagens enviadas por mim ───────────────────────────
if ($fromMe) {
    echo json_encode(['ok' => true, 'skipped' => 'fromMe']);
    exit;
}

// ── Extrai número limpo ──────────────────────────────────────────
$whatsapp = preg_replace('/@.*$/', '', $remoteJid);
$whatsapp = preg_replace('/[^0-9]/', '', $whatsapp);

if (strlen($whatsapp) < 8) {
    echo json_encode(['ok' => false, 'error' => 'invalid_number', 'raw' => $remoteJid]);
    exit;
}

// ── Mapeia intent → dados normalizados ──────────────────────────
function normalizar_intent(?string $raw): array {
    if (!$raw) return ['intent' => 'outro', 'titulo' => 'Atendimento IA', 'confidence' => 0.5];

    $lower = mb_strtolower($raw, 'UTF-8');

    $map = [
        'agenda'      => ['intent' => 'agendamento',       'titulo' => 'Agendamento Barbearia',  'confidence' => 0.98],
        'barbearia'   => ['intent' => 'agendamento',       'titulo' => 'Agendamento Barbearia',  'confidence' => 0.98],
        'catálogo'    => ['intent' => 'interesse_produto', 'titulo' => 'Catálogo de Produtos',   'confidence' => 0.98],
        'catalogo'    => ['intent' => 'interesse_produto', 'titulo' => 'Catálogo de Produtos',   'confidence' => 0.98],
        'roupas'      => ['intent' => 'interesse_produto', 'titulo' => 'Catálogo de Produtos',   'confidence' => 0.95],
        'atendente'   => ['intent' => 'falar_atendente',   'titulo' => 'Falar com Atendente',    'confidence' => 0.98],
        'gerente'     => ['intent' => 'falar_atendente',   'titulo' => 'Falar com Atendente',    'confidence' => 0.95],
        'localização' => ['intent' => 'localizacao',       'titulo' => 'Localização da Loja',    'confidence' => 0.98],
        'localizacao' => ['intent' => 'localizacao',       'titulo' => 'Localização da Loja',    'confidence' => 0.98],
        'saudacao'    => ['intent' => 'saudacao',          'titulo' => 'Primeiro Contato',       'confidence' => 0.99],
        'saudação'    => ['intent' => 'saudacao',          'titulo' => 'Primeiro Contato',       'confidence' => 0.99],
    ];

    foreach ($map as $keyword => $data) {
        if (str_contains($lower, mb_strtolower($keyword, 'UTF-8'))) {
            return $data;
        }
    }

    return ['intent' => 'outro', 'titulo' => $raw, 'confidence' => 0.7];
}

$intentData = normalizar_intent($crmIntent ?: $msgText);
$intent     = $intentData['intent'];
$titulo     = $intentData['titulo'];
$confidence = $intentData['confidence'];

$resumo = json_encode([
    'intent'     => $intent,
    'confidence' => $confidence,
    'raw'        => $crmIntent ?: $msgText,
    'origem'     => $crmOrigem,
], JSON_UNESCAPED_UNICODE);

// ── Banco ────────────────────────────────────────────────────────
try {
    $pdo = get_pdo();

    // Descobre company_id pela instância, ou pega a primeira
    $companyId = null;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('evolution_instance', $cols)) {
            $s = $pdo->prepare('SELECT id FROM companies WHERE evolution_instance = ? LIMIT 1');
            $s->execute([$instance]);
            $companyId = $s->fetchColumn() ?: null;
        }
    } catch(Throwable $e) {}

    if (!$companyId) {
        $row = $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1')->fetch();
        $companyId = $row ? (int)$row['id'] : null;
    }

    if (!$companyId) {
        echo json_encode(['ok' => false, 'error' => 'no_company']);
        exit;
    }

    // ── Cliente: busca ou cria ───────────────────────────────────
    $s = $pdo->prepare('SELECT id, nome FROM clients WHERE company_id=? AND whatsapp=? LIMIT 1');
    $s->execute([$companyId, $whatsapp]);
    $existing = $s->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $clientId = (int)$existing['id'];
        // Atualiza nome se estava vazio
        if ($pushName && empty($existing['nome'])) {
            $pdo->prepare('UPDATE clients SET nome=?, updated_at=NOW() WHERE id=?')
                ->execute([$pushName, $clientId]);
        }
    } else {
        $nome = $pushName ?: ('WA ' . substr($whatsapp, -4));
        $pdo->prepare('INSERT INTO clients (company_id,nome,whatsapp,origem,created_at,updated_at) VALUES (?,?,?,"whatsapp",NOW(),NOW())')
            ->execute([$companyId, $nome, $whatsapp]);
        $clientId = (int)$pdo->lastInsertId();
    }

    // ── Anti-duplicata: mesmo cliente + mesmo título nos últimos 2 min ──
    $s = $pdo->prepare("
        SELECT id FROM interactions
        WHERE company_id=? AND client_id=? AND titulo=?
          AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
        LIMIT 1
    ");
    $s->execute([$companyId, $clientId, $titulo]);
    if ($s->fetchColumn()) {
        echo json_encode(['ok' => true, 'skipped' => 'duplicate_2min']);
        exit;
    }

    // ── Descobre colunas reais da tabela interactions ────────────
    $icols = $pdo->query('SHOW COLUMNS FROM interactions')->fetchAll(PDO::FETCH_COLUMN);
    $hasUpdatedAt = in_array('updated_at', $icols);
    $hasCanal     = in_array('canal',      $icols);
    $hasOrigem    = in_array('origem',     $icols);

    // Adiciona ou corrige colunas faltantes
    if (!$hasCanal)  $pdo->exec("ALTER TABLE interactions ADD COLUMN canal  VARCHAR(50) DEFAULT 'whatsapp'");
    if (!$hasOrigem) $pdo->exec("ALTER TABLE interactions ADD COLUMN origem VARCHAR(50) DEFAULT 'bot'");
    // Garante tamanho suficiente (caso tenha sido criada com tamanho menor)
    try { $pdo->exec("ALTER TABLE interactions MODIFY COLUMN origem VARCHAR(50)"); } catch(Throwable $e) {}
    try { $pdo->exec("ALTER TABLE interactions MODIFY COLUMN canal  VARCHAR(50)"); } catch(Throwable $e) {}

    // Trunca valores para caber nas colunas (segurança extra)
    $crmOrigemSafe = substr($crmOrigem, 0, 50);

    // ── Salva interação ──────────────────────────────────────────
    if ($hasUpdatedAt) {
        $pdo->prepare('
            INSERT INTO interactions (company_id, client_id, titulo, resumo, canal, origem, created_at, updated_at)
            VALUES (?, ?, ?, ?, "whatsapp", ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ')->execute([$companyId, $clientId, $titulo, $resumo, $crmOrigemSafe]);
    } else {
        $pdo->prepare('
            INSERT INTO interactions (company_id, client_id, titulo, resumo, canal, origem, created_at)
            VALUES (?, ?, ?, ?, "whatsapp", ?, UTC_TIMESTAMP())
        ')->execute([$companyId, $clientId, $titulo, $resumo, $crmOrigemSafe]);
    }

    $interactionId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok'             => true,
        'interaction_id' => $interactionId,
        'client_id'      => $clientId,
        'intent'         => $intent,
        'titulo'         => $titulo,
        'whatsapp'       => $whatsapp,
        'nome'           => $pushName,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}