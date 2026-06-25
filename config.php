<?php
// Força o PHP a reconhecer o HTTPS vindo do Proxy do Coolify/Cloudflare
$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$cfVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
if ($forwardedProto === 'https' || (is_array($cfVisitor) && strtolower((string)($cfVisitor['scheme'] ?? '')) === 'https')) {
    $_SERVER['HTTPS'] = 'on';
}

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
function crm_request_scheme(): string
{
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'https') return 'https';

    $cfVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
    if (is_array($cfVisitor) && strtolower((string)($cfVisitor['scheme'] ?? '')) === 'https') return 'https';

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https';
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return 'https';

    return 'http';
}

function crm_detect_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = trim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($scriptDir === '' || $scriptDir === '.') return '';

    $scriptFile = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $appRoot = realpath(__DIR__);
    if (!$scriptFile || !$appRoot || strpos($scriptFile, $appRoot) !== 0) {
        return '/' . $scriptDir;
    }

    $relative = trim(str_replace('\\', '/', substr($scriptFile, strlen($appRoot))), '/');
    $relativeDir = trim(str_replace('\\', '/', dirname($relative)), '/');
    if ($relativeDir !== '' && $relativeDir !== '.' && str_ends_with($scriptDir, '/' . $relativeDir)) {
        $scriptDir = substr($scriptDir, 0, -strlen('/' . $relativeDir));
    }

    return $scriptDir !== '' ? '/' . trim($scriptDir, '/') : '';
}

function crm_detect_base_url(): string
{
    $envBaseUrl = rtrim((string)(getenv('BASE_URL') ?: ''), '/');
    if (PHP_SAPI === 'cli') return $envBaseUrl;

    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return $envBaseUrl;

    return crm_request_scheme() . '://' . $host . crm_detect_base_path();
}

define('BASE_URL', crm_detect_base_url());

// =========================
// API DO HERMES AGENT
// =========================
if (!defined('HERMES_API_TOKEN')) {
    define('HERMES_API_TOKEN', getenv('HERMES_API_TOKEN') ?: '617a9464710c73125b5f3ed3fc46df6f95bdf999713089d487fc551129028257');
}
if (!defined('HERMES_AGENT_URL')) {
    define('HERMES_AGENT_URL', getenv('HERMES_AGENT_URL') ?: 'https://hermes.formenstore.com.br');
}

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
define('API_SECRET', getenv('API_TOKEN_IA') ?: ($_ENV['API_TOKEN_IA'] ?? 'fallback-aqui'));

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
