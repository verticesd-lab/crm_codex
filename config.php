<?php
// =========================
// CONFIGURAÇÕES DO SISTEMA
// =========================

ini_set('default_charset', 'UTF-8');

// ✅ Recomendado: PHP em UTC no config (base do sistema).
// A exibição em "America/Cuiaba" deve ser feita no helpers (conversão).
date_default_timezone_set('UTC');

// Nome do sistema
define('APP_NAME', 'Micro CRM SaaS');

// =========================
// CAMINHO BASE (BASE_URL)
// =========================
// No Coolify: defina BASE_URL="/" nas variáveis de ambiente.
// Em local: fallback para "".
$envBaseUrl = getenv('BASE_URL');
if ($envBaseUrl === false) {
    $envBaseUrl = '';
}
// remove barra no final pra evitar "//uploads"
$envBaseUrl = rtrim($envBaseUrl, '/');
define('BASE_URL', $envBaseUrl);

// =========================
// BANCO DE DADOS
// =========================

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

define('DB_HOST', $dbHost !== false && $dbHost !== '' ? $dbHost : 'localhost');
define('DB_NAME', $dbName !== false && $dbName !== '' ? $dbName : 'crm_codex');
define('DB_USER', $dbUser !== false && $dbUser !== '' ? $dbUser : 'root');
define('DB_PASS', $dbPass !== false && $dbPass !== '' ? $dbPass : '');

// =========================
// TOKEN PARA AGENTES DE IA
// =========================

$envTokenIa = getenv('API_TOKEN_IA');
define(
    'API_TOKEN_IA',
    $envTokenIa !== false && $envTokenIa !== ''
        ? $envTokenIa
        : 'minha_chave_super_secreta_local_dev'
);

// =========================
// CONFIGURAÇÕES DE UPLOADS
// =========================

// Caminho físico da pasta /uploads
define('UPLOAD_DIR', __DIR__ . '/uploads');

// URL pública da pasta de uploads
define('UPLOAD_URL', BASE_URL . '/uploads');

// Limite de upload
define('MAX_UPLOAD_SIZE_MB', 5);

// Tamanho máximo das imagens (se quiser redimensionar futuramente)
define('MAX_IMAGE_WIDTH', 1600);
define('MAX_IMAGE_HEIGHT', 1600);

// Tipos permitidos
define('ALLOWED_IMAGE_MIMES', [
    'image/jpeg',
    'image/png',
    'image/webp',
]);

// =========================
// VALIDAÇÃO DE TOKEN PARA API
// =========================

/**
 * Retorna headers normalizados (minúsculo).
 */
function apiGetHeaders(): array
{
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $headers[$name] = $v;
        }
    }
    // Alguns servidores colocam Authorization fora do HTTP_AUTHORIZATION
    if (!empty($_SERVER['AUTHORIZATION']) && empty($headers['authorization'])) {
        $headers['authorization'] = $_SERVER['AUTHORIZATION'];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && empty($headers['authorization'])) {
        $headers['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
    }
    return $headers;
}

/**
 * Lê o RAW apenas uma vez e guarda em cache.
 */
function apiGetRawBody(): string
{
    if (isset($GLOBALS['__RAW_INPUT__']) && is_string($GLOBALS['__RAW_INPUT__'])) {
        return $GLOBALS['__RAW_INPUT__'];
    }
    $raw = file_get_contents('php://input');
    $GLOBALS['__RAW_INPUT__'] = is_string($raw) ? $raw : '';
    return $GLOBALS['__RAW_INPUT__'];
}

/**
 * Tenta extrair token de vários lugares.
 */
function apiExtractToken(): string
{
    // 1) GET/POST
    $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
    if ($token !== '') return $token;

    // 2) Headers (Activepieces/Waha costuma mandar em header)
    $headers = apiGetHeaders();

    // 2.1) Authorization: Bearer xxx
    if (!empty($headers['authorization'])) {
        $auth = trim((string)$headers['authorization']);
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $t = trim($m[1]);
            if ($t !== '') return $t;
        }
        // também aceita Authorization: <token>
        if ($auth !== '') return $auth;
    }

    // 2.2) X-API-Token: xxx (ou x-api-key)
    if (!empty($headers['x-api-token'])) {
        $t = trim((string)$headers['x-api-token']);
        if ($t !== '') return $t;
    }
    if (!empty($headers['x-api-key'])) {
        $t = trim((string)$headers['x-api-key']);
        if ($t !== '') return $t;
    }

    // 3) JSON body: { "token": "xxx" }
    $raw = apiGetRawBody();
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['token'])) {
            $t = trim((string)$decoded['token']);
            if ($t !== '') return $t;
        }
    }

    return '';
}

/**
 * Valida token da API.
 * Use no começo dos endpoints que precisam de proteção.
 */
function checkApiToken(): void
{
    $token = apiExtractToken();

    if ($token !== '' && hash_equals((string)API_TOKEN_IA, (string)$token)) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'data'    => null,
        'error'   => 'Token ausente ou inválido',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
