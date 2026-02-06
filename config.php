<?php
// =========================
// CONFIGURAÇÕES DO SISTEMA
// =========================

ini_set('default_charset', 'UTF-8');

// ✅ Base do sistema em UTC (exibição pode ser convertida nos helpers)
date_default_timezone_set('UTC');

define('APP_NAME', 'Micro CRM SaaS');

// =========================
// CAMINHO BASE (BASE_URL)
// =========================
$envBaseUrl = getenv('BASE_URL');
if ($envBaseUrl === false) $envBaseUrl = '';
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
// SEGREDO DA API (MCP)
// =========================
$envApiSecret = getenv('API_SECRET');
define(
    'API_SECRET',
    $envApiSecret !== false && $envApiSecret !== ''
        ? $envApiSecret
        : 'mude_este_api_secret_no_ambiente'
);

// =========================
// CONFIGURAÇÕES CHATWOOT
// =========================
define('CHATWOOT_BASE_URL', 'https://chat.formenstore.com.br');
define('CHATWOOT_ACCOUNT_ID', 1);
define('CHATWOOT_API_TOKEN', 'phFniTSrc4gRW7GZMALwygZU');

// =========================
// CONFIGURAÇÕES DE UPLOADS
// =========================
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');
define('MAX_UPLOAD_SIZE_MB', 5);

define('MAX_IMAGE_WIDTH', 1600);
define('MAX_IMAGE_HEIGHT', 1600);

define('ALLOWED_IMAGE_MIMES', [
    'image/jpeg',
    'image/png',
    'image/webp',
]);

// =========================
// VALIDAÇÃO DE TOKEN PARA API
// =========================

// evita "Cannot redeclare" se algum include duplicar
if (!function_exists('checkApiToken')) {

    function checkApiToken(): void
    {
        $token = '';

        // 1) Header: X-API-Token
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        }

        // normaliza (case-insensitive)
        $xApiToken = '';
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-API-Token') === 0) {
                $xApiToken = (string)$v;
                break;
            }
        }
        if ($xApiToken !== '') {
            $token = trim($xApiToken);
        }

        // 2) Header: Authorization: Bearer <token>
        if ($token === '') {
            $auth = '';
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $auth = (string)$v;
                    break;
                }
            }
            $auth = trim($auth);
            if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
                $token = trim($m[1]);
            }
        }

        // 3) GET / POST
        if ($token === '') {
            $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
            $token = trim($token);
        }

        // 4) JSON body {"token": "..."}
        if ($token === '') {
            $raw = $GLOBALS['__RAW_INPUT__'] ?? file_get_contents('php://input');
            if ($raw !== false && $raw !== null && $raw !== '') {
                $GLOBALS['__RAW_INPUT__'] = $raw; // cache
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded) && isset($decoded['token'])) {
                    $token = trim((string)$decoded['token']);
                }
            }
        }

        // valida token
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
}
