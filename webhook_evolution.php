<?php
/**
 * webhook_evolution.php
 * Recebe POSTs do ActivePieces com eventos da Evolution API
 * e salva interações no CRM.
 *
 * Payload esperado (enviado pelo ActivePieces):
 * {
 *   "data": {
 *     "event": "messages.upsert",
 *     "instance": "loja_oficial",
 *     "_crm_intent":  "Agenda Barbearia ✂️"   (ou "saudacao", etc.)
 *     "_crm_origem":  "activepieces",
 *     "data": {
 *       "key":         { "remoteJid": "556599...@s.whatsapp.net", "fromMe": false },
 *       "pushName":    "Patrick Lemes",
 *       "messageType": "conversation" | "pollUpdateMessage",
 *       "message":     { "conversation": "texto da mensagem" }
 *     }
 *   }
 * }
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// ── Responde imediatamente ao ActivePieces para não dar timeout ──
http_response_code(200);
header('Content-Type: application/json');

// ── Lê o payload ──────────────────────────────────────────────────
$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (empty($payload)) {
    echo json_encode(['ok' => false, 'error' => 'empty payload']);
    exit;
}

// Suporta tanto { data: {...} } quanto o objeto direto
$root = $payload['data'] ?? $payload;

$event    = $root['event']        ?? '';
$instance = $root['instance']     ?? '';
$crmIntent= $root['_crm_intent']  ?? null; // campo especial inserido pelo ActivePieces
$crmOrigem= $root['_crm_origem']  ?? 'whatsapp';

$msgData  = $root['data']         ?? [];   // dados da mensagem em si

$remoteJid   = $msgData['key']['remoteJid'] ?? '';
$fromMe      = (bool)($msgData['key']['fromMe'] ?? false);
$pushName    = $msgData['pushName']          ?? '';
$messageType = $msgData['messageType']       ?? 'conversation';
$msgText     = $msgData['message']['conversation']
            ?? $msgData['message']['extendedTextMessage']['text']
            ?? '';

// ── Ignora mensagens enviadas por mim ────────────────────────────
if ($fromMe) {
    echo json_encode(['ok' => true, 'skipped' => 'fromMe']);
    exit;
}

// ── Extrai número limpo (remove @s.whatsapp.net, etc.) ───────────
$whatsapp = preg_replace('/@.*$/', '', $remoteJid);
$whatsapp = preg_replace('/[^0-9]/', '', $whatsapp);

if (strlen($whatsapp) < 8) {
    echo json_encode(['ok' => false, 'error' => 'invalid number: ' . $whatsapp]);
    exit;
}

// ── Mapeia _crm_intent (texto da enquete) → intent normalizado ───
function normalizar_intent(?string $raw): array {
    if (!$raw) return ['intent' => 'outro', 'titulo' => 'Atendimento IA', 'confidence' => 0.5];

    $raw_lower = mb_strtolower($raw, 'UTF-8');

    $map = [
        'agenda'      => ['intent' => 'agendamento',       'titulo' => 'Agendamento Barbearia',     'confidence' => 0.98],
        'barbearia'   => ['intent' => 'agendamento',       'titulo' => 'Agendamento Barbearia',     'confidence' => 0.98],
        'catálogo'    => ['intent' => 'interesse_produto', 'titulo' => 'Catálogo de Produtos',      'confidence' => 0.98],
        'catalogo'    => ['intent' => 'interesse_produto', 'titulo' => 'Catálogo de Produtos',      'confidence' => 0.98],
        'roupas'      => ['intent' => 'interesse_produto', 'titulo' => 'Catálogo de Produtos',      'confidence' => 0.95],
        'atendente'   => ['intent' => 'falar_atendente',   'titulo' => 'Falar com Atendente',       'confidence' => 0.98],
        'gerente'     => ['intent' => 'falar_atendente',   'titulo' => 'Falar com Atendente',       'confidence' => 0.95],
        'localização' => ['intent' => 'localizacao',       'titulo' => 'Localização da Loja',       'confidence' => 0.98],
        'localizacao' => ['intent' => 'localizacao',       'titulo' => 'Localização da Loja',       'confidence' => 0.98],
        'saudacao'    => ['intent' => 'saudacao',          'titulo' => 'Primeiro Contato',          'confidence' => 0.99],
        'saudação'    => ['intent' => 'saudacao',          'titulo' => 'Primeiro Contato',          'confidence' => 0.99],
    ];

    foreach ($map as $keyword => $data) {
        if (str_contains($raw_lower, mb_strtolower($keyword, 'UTF-8'))) {
            return $data;
        }
    }

    // Fallback: usa o texto bruto como título
    return ['intent' => 'outro', 'titulo' => $raw, 'confidence' => 0.7];
}

$intentData = normalizar_intent($crmIntent ?: $msgText);
$intent     = $intentData['intent'];
$titulo     = $intentData['titulo'];
$confidence = $intentData['confidence'];

// ── Resumo JSON (formato padrão do CRM) ─────────────────────────
$resumo = json_encode([
    'intent'     => $intent,
    'confidence' => $confidence,
    'raw'        => $crmIntent ?: $msgText,
    'origem'     => $crmOrigem,
], JSON_UNESCAPED_UNICODE);

// ── Banco de dados ───────────────────────────────────────────────
try {
    $pdo = get_pdo();

    // Descobre o company_id pela instância da Evolution API
    // Se a tabela companies tiver campo "evolution_instance", usa ele
    // Senão, pega a primeira empresa (sistema single-tenant)
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
        // Fallback: primeira empresa
        $s = $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1');
        $row = $s->fetch();
        $companyId = $row ? (int)$row['id'] : null;
    }

    if (!$companyId) {
        echo json_encode(['ok' => false, 'error' => 'no company found']);
        exit;
    }

    // ── Cria ou encontra o cliente ───────────────────────────────
    $clientId = null;

    $s = $pdo->prepare('SELECT id FROM clients WHERE company_id = ? AND whatsapp = ? LIMIT 1');
    $s->execute([$companyId, $whatsapp]);
    $existingClient = $s->fetchColumn();

    if ($existingClient) {
        $clientId = (int)$existingClient;
        // Atualiza pushName se estava vazio
        if ($pushName) {
            $pdo->prepare('UPDATE clients SET nome = COALESCE(NULLIF(nome,""), ?), updated_at = NOW() WHERE id = ?')
                ->execute([$pushName, $clientId]);
        }
    } else {
        // Cria novo cliente
        $nome = $pushName ?: ('WA ' . substr($whatsapp, -4));
        $pdo->prepare('
            INSERT INTO clients (company_id, nome, whatsapp, origem, created_at, updated_at)
            VALUES (?, ?, ?, "whatsapp", NOW(), NOW())
        ')->execute([$companyId, $nome, $whatsapp]);
        $clientId = (int)$pdo->lastInsertId();
    }

    // ── Evita duplicatas (mesmo cliente + mesmo intent nos últimos 2 min) ──
    $s = $pdo->prepare("
        SELECT id FROM interactions
        WHERE company_id = ? AND client_id = ?
          AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
          AND titulo = ?
        LIMIT 1
    ");
    $s->execute([$companyId, $clientId, $titulo]);
    if ($s->fetchColumn()) {
        echo json_encode(['ok' => true, 'skipped' => 'duplicate_within_2min']);
        exit;
    }

    // ── Garante colunas extras na tabela interactions ────────────
    try {
        $icols = $pdo->query('SHOW COLUMNS FROM interactions')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('canal', $icols))  $pdo->exec("ALTER TABLE interactions ADD COLUMN canal VARCHAR(50) DEFAULT 'whatsapp'");
        if (!in_array('origem', $icols)) $pdo->exec("ALTER TABLE interactions ADD COLUMN origem VARCHAR(50) DEFAULT 'bot'");
    } catch(Throwable $e) {}

    // ── Salva a interação ────────────────────────────────────────
    $pdo->prepare('
        INSERT INTO interactions
            (company_id, client_id, titulo, resumo, canal, origem, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, "whatsapp", ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ')->execute([$companyId, $clientId, $titulo, $resumo, $crmOrigem]);

    $interactionId = (int)$pdo->lastInsertId();

    // ── Log para debug (opcional, remova em produção) ────────────
    // file_put_contents(__DIR__ . '/logs/webhook.log', date('Y-m-d H:i:s') . " [$whatsapp] $titulo\n", FILE_APPEND);

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