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

function checkApiToken(): void
{
    // Tenta GET / POST primeiro
    $token = $_GET['token'] ?? ($_POST['token'] ?? '');

    // Se não vier, tenta ler do corpo RAW (JSON)
    if ($token === '') {
        $raw = $GLOBALS['__RAW_INPUT__'] ?? file_get_contents('php://input');

        if ($raw !== false && $raw !== null && $raw !== '') {
            // Cache do RAW pra não ler duas vezes
            $GLOBALS['__RAW_INPUT__'] = $raw;
            $decoded = json_decode($raw, true);

            if (is_array($decoded) && isset($decoded['token'])) {
                $token = (string)$decoded['token'];
            }
        }
    }

    // Valida token
    if ($token === API_TOKEN_IA) {
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
