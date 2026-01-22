<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

if (function_exists('checkApiToken')) {
    checkApiToken();
}

header('Content-Type: application/json; charset=utf-8');

$pdo = get_pdo();
$input = getMergedInput();

$companyId = (int)($input['company_id'] ?? 0);
$clientId  = (int)($input['client_id'] ?? 0);

if (!$companyId || !$clientId) {
    apiJsonError('Informe company_id e client_id');
}

/**
 * Tenta transformar o campo "resumo" em algo agradável para UI.
 * - Se for JSON com {intent, confidence}, vira "intent (99%)"
 * - Se for JSON genérico, devolve uma string resumida
 * - Se não for JSON, devolve o texto original
 */
function buildResumoDisplay($resumo): array
{
    $raw = is_string($resumo) ? trim($resumo) : '';

    if ($raw === '') {
        return ['parsed' => null, 'display' => ''];
    }

    $parsed = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
        // caso padrão do IA: { intent, confidence }
        $intent = $parsed['intent'] ?? null;
        $confidence = $parsed['confidence'] ?? null;

        if (is_string($intent) && $intent !== '') {
            if (is_numeric($confidence)) {
                $pct = (int) round(((float)$confidence) * 100);
                return ['parsed' => $parsed, 'display' => $intent . " ({$pct}%)"];
            }
            return ['parsed' => $parsed, 'display' => $intent];
        }

        // JSON genérico: transforma em uma linha curta
        $compact = json_encode($parsed, JSON_UNESCAPED_UNICODE);
        if (is_string($compact)) {
            // limita tamanho pra não quebrar UI
            if (mb_strlen($compact) > 220) {
                $compact = mb_substr($compact, 0, 220) . '...';
            }
            return ['parsed' => $parsed, 'display' => $compact];
        }

        return ['parsed' => $parsed, 'display' => $raw];
    }

    // não é JSON: devolve texto (limitado)
    if (mb_strlen($raw) > 220) {
        $raw = mb_substr($raw, 0, 220) . '...';
    }

    return ['parsed' => null, 'display' => $raw];
}

try {
    $clientStmt = $pdo->prepare('
        SELECT *
        FROM clients
        WHERE id = ? AND company_id = ?
        LIMIT 1
    ');
    $clientStmt->execute([$clientId, $companyId]);
    $client = $clientStmt->fetch();

    if (!$client) {
        apiJsonError('Cliente nao encontrado nesta empresa', 404);
    }

    // interações (limite pra não estourar payload)
    $interactionsStmt = $pdo->prepare('
        SELECT id, canal, origem, titulo, resumo, atendente, created_at
        FROM interactions
        WHERE company_id = ? AND client_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ');
    $interactionsStmt->execute([$companyId, $clientId]);
    $interactions = $interactionsStmt->fetchAll();

    // melhora resumo para UI
    foreach ($interactions as &$it) {
        $info = buildResumoDisplay($it['resumo'] ?? '');
        $it['resumo_parsed']  = $info['parsed'];   // array|null
        $it['resumo_display'] = $info['display'];  // string
    }
    unset($it);

    $ordersStmt = $pdo->prepare('
        SELECT id, origem, status, total, created_at
        FROM orders
        WHERE company_id = ? AND client_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ');
    $ordersStmt->execute([$companyId, $clientId]);

    apiJsonResponse(true, [
        'client' => $client,
        'interactions' => $interactions,
        'orders' => $ordersStmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    apiJsonError('Erro ao montar timeline do cliente', 500);
}
