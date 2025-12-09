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
