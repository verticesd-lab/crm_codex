<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Lê o corpo JSON e guarda para reuso.
 */
function getJsonInput(): array
{
    if (isset($GLOBALS['__API_JSON_BODY__'])) {
        return $GLOBALS['__API_JSON_BODY__'];
    }

    $raw = $GLOBALS['__RAW_INPUT__'] ?? file_get_contents('php://input');
    $GLOBALS['__RAW_INPUT__'] = $raw;

    $decoded = json_decode((string)$raw, true);
    $GLOBALS['__API_JSON_BODY__'] = is_array($decoded) ? $decoded : [];

    return $GLOBALS['__API_JSON_BODY__'];
}

/**
 * Junta GET, POST e JSON para facilitar leituras.
 */
function getMergedInput(): array
{
    return array_merge($_GET, $_POST, getJsonInput());
}

function apiJsonResponse(bool $success, $data = null, ?string $error = null, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function apiJsonError(string $message, int $statusCode = 400): void
{
    apiJsonResponse(false, null, $message, $statusCode);
}

/**
 * ============================
 * AUTH API TOKEN
 * ============================
 * Aceita token em:
 * - Header: Authorization: Bearer <token>
 * - Header: X-API-Token: <token>
 * - Query/Body: token=<token>  (fallback)
 *
 * Configure no config.php:
 *   define('API_TOKEN', 'SEU_TOKEN_FORTE_AQUI');
 */
function checkApiToken(): void
{
    $expected = defined('API_TOKEN') ? (string)API_TOKEN : '';

    if ($expected === '') {
        apiJsonError('API_TOKEN não configurado no config.php', 500);
    }

    $token = '';

    // 1) Authorization: Bearer
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        $token = trim($m[1]);
    }

    // 2) X-API-Token
    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
    }

    // 3) fallback token via GET/POST/JSON
    if ($token === '') {
        $input = getMergedInput();
        $token = trim((string)($input['token'] ?? ''));
    }

    if ($token === '' || !hash_equals($expected, $token)) {
        apiJsonError('Token ausente ou inválido', 401);
    }
}
