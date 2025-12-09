<?php
// Configuracoes basicas do sistema
define('APP_NAME', 'Micro CRM SaaS');
define('BASE_URL', '');

// Ajuste as credenciais conforme seu ambiente de hospedagem
define('DB_HOST', 'localhost');
define('DB_NAME', 'crm_codex');
define('DB_USER', 'root');
define('DB_PASS', '');

// Token simples para uso da API por agentes de IA
define('API_TOKEN_IA', 'minha_chave_super_secreta');

date_default_timezone_set('America/Sao_Paulo');

/**
 * Valida o token de API vindo via GET, POST ou JSON e encerra com JSON de erro se nao corresponder.
 */
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
        'error' => 'Token ausente ou invalido',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
