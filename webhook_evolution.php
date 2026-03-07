<?php
/**
 * webhook_evolution.php
 * Recebe POSTs do ActivePieces → salva interactions no CRM
 *
 * [v2 - integrado com sistema de Reativação de Clientes]
 * Quando um cliente responde qualquer mensagem e está em campanha
 * de reativação, o status é atualizado automaticamente.
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

// ════════════════════════════════════════════════════════════════
// REATIVAÇÃO — marca cliente como "respondeu" se estava em campanha
// ════════════════════════════════════════════════════════════════
/**
 * Verifica se o remetente é um cliente em campanha de reativação
 * e, se for, atualiza os status correspondentes.
 * Encapsulado em try/catch — nunca quebra o fluxo principal.
 */
function reativacao_check_response(PDO $pdo, int $companyId, string $rawPhone): void
{
    $digits = preg_replace('/\D/', '', $rawPhone);
    if (!$digits) return;

    $wa55 = str_starts_with($digits, '55') ? $digits : '55' . $digits;
    $waLc = str_starts_with($digits, '55') ? substr($digits, 2) : $digits;

    try {
        // Busca cliente pelo número (testa com e sem DDI)
        $stmt = $pdo->prepare("
            SELECT id, reativ_status, reativ_tentativas
            FROM clients
            WHERE company_id = ?
              AND (whatsapp = ? OR whatsapp = ?
                   OR telefone_principal = ? OR telefone_principal = ?)
            LIMIT 1
        ");
        $stmt->execute([$companyId, $wa55, $waLc, $wa55, $waLc]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) return;

        $currentStatus = (string)($client['reativ_status'] ?? '');
        $tentativas    = (int)($client['reativ_tentativas'] ?? 0);

        // Só processa se estava aguardando resposta de algum lote
        if (!in_array($currentStatus, ['lote_enviado_1', 'lote_enviado_2', 'aguardando_2'], true)) {
            return;
        }

        $novoStatus = $tentativas <= 1 ? 'respondeu_1' : 'respondeu_2';

        // Atualiza o cliente
        $pdo->prepare("
            UPDATE clients
               SET reativ_status = ?,
                   updated_at    = NOW()
             WHERE id = ? AND company_id = ?
        ")->execute([$novoStatus, $client['id'], $companyId]);

        // Marca o envio mais recente como respondido
        $pdo->prepare("
            UPDATE reativacao_envios
               SET status       = 'respondeu',
                   respondeu_em = NOW()
             WHERE client_id  = ?
               AND company_id = ?
               AND status     = 'enviado'
             ORDER BY id DESC
             LIMIT 1
        ")->execute([$client['id'], $companyId]);

        error_log(sprintf(
            '[reativacao] Cliente #%d (%s) respondeu. %s → %s',
            $client['id'], $rawPhone, $currentStatus, $novoStatus
        ));

    } catch (Throwable $e) {
        // Nunca deixa o patch quebrar o fluxo principal do webhook
        error_log('[reativacao_check_response] Erro: ' . $e->getMessage());
    }
}
// ════════════════════════════════════════════════════════════════

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
    try { $pdo->exec("ALTER TABLE interactions MODIFY COLUMN origem VARCHAR(50)"); } catch(Throwable $e) {}
    try { $pdo->exec("ALTER TABLE interactions MODIFY COLUMN canal  VARCHAR(50)"); } catch(Throwable $e) {}

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

    // ── Reativação: verifica se cliente estava em campanha ───────
    // Chamada após salvar interação — nunca bloqueia o retorno
    reativacao_check_response($pdo, $companyId, $whatsapp);

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