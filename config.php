<?php
// =========================
// CONFIGURAÇÕES DO SISTEMA
// =========================

// Nome do sistema
define('APP_NAME', 'Micro CRM SaaS');

// Caminho base da aplicação (em localhost = /crm_codex)
define('BASE_URL', '/crm_codex');

// =========================
// BANCO DE DADOS
// =========================

define('DB_HOST', 'localhost');
define('DB_NAME', 'crm_codex');
define('DB_USER', 'root');
define('DB_PASS', '');

// =========================
// TOKEN PARA AGENTES DE IA
// =========================
define('API_TOKEN_IA', 'minha_chave_super_secreta');

date_default_timezone_set('America/Sao_Paulo');

// =========================
// CONFIGURAÇÕES DE UPLOADS
// =========================

// Caminho físico da pasta /uploads
define('UPLOAD_DIR', __DIR__ . '/uploads');

// URL pública
define('UPLOAD_URL', BASE_URL . '/uploads');

// Limite de upload
define('MAX_UPLOAD_SIZE_MB', 5);

// Tamanho máximo das imagens (se quiser redimensionar futuramente)
define('MAX_IMAGE_WIDTH', 1600);
define('MAX_IMAGE_HEIGHT', 1600);

// Tipos permitidos (AGORA CORRETO)
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
    $token = $_GET['token'] ?? ($_POST['token'] ?? '');

    if ($token === '') {
        $raw = $GLOBALS['__RAW_INPUT__'] ?? file_get_contents('php://input');
        if ($raw !== false && $raw !== null && $raw !== '') {
            $GLOBALS['__RAW_INPUT__'] = $raw;
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['token'])) {
                $token = (string)$decoded['token'];
            }
        }
    }

    if ($token === API_TOKEN_IA) {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'data' => null,
        'error' => 'Token ausente ou inválido',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
