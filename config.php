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
$envBaseUrl = rtrim((string)$envBaseUrl, '/');
define('BASE_URL', $envBaseUrl);

// =========================
// BANCO DE DADOS
// =========================

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

define('DB_HOST', $dbHost !== false && $dbHost !== '' ? (string)$dbHost : 'localhost');
define('DB_NAME', $dbName !== false && $dbName !== '' ? (string)$dbName : 'crm_codex');
define('DB_USER', $dbUser !== false && $dbUser !== '' ? (string)$dbUser : 'root');
define('DB_PASS', $dbPass !== false && $dbPass !== '' ? (string)$dbPass : '');

// =========================
// TOKEN PARA AGENTES DE IA
// =========================

$envTokenIa = getenv('API_TOKEN_IA');
$envTokenIa = $envTokenIa !== false ? trim((string)$envTokenIa) : '';

define(
    'API_TOKEN_IA',
    $envTokenIa !== ''
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
// HELPERS DE HEADER
// =========================

function getRequestHeader(string $name): string
{
    $nameLower = strtolower($name);

    // Tenta getallheaders() se existir
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === $nameLower) {
                    return trim((string)$v);
                }
            }
        }
    }

    // Fallback via $_SERVER
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return trim((string)$_SERVER[$key]);
    }

    // Alguns servers colocam Authorization aqui:
    if ($nameLower === 'authorization' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    return '';
}

// =========================
// VALIDAÇÃO DE TOKEN PARA API
// =========================

function checkApiToken(): void
{
    $token = '';

    // 1) Header preferencial: X-API-Token
    $token = getRequestHeader('X-API-Token');

    // 2) Header Authorization: Bearer <token>
    if ($token === '') {
        $auth = getRequestHeader('Authorization');
        if ($auth !== '' && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            $token = trim((string)$m[1]);
        }
    }

    // 3) GET / POST
    if ($token === '') {
        $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
        $token = trim($token);
    }

    // 4) JSON body
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

    // compara (sempre trimmed)
    $expected = trim((string)API_TOKEN_IA);

    if ($token !== '' && hash_equals($expected, $token)) {
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
