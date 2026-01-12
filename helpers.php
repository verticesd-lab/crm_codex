<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ========= TIMEZONE (Cuiabá/MT) =========
 * Cuiabá = America/Cuiaba
 * Isso ajusta todas as datas geradas pelo PHP (date(), time(), etc.)
 */
function app_timezone(): string {
    return 'America/Cuiaba';
}

function apply_app_timezone(): void {
    $tz = app_timezone();
    if (function_exists('date_default_timezone_set')) {
        date_default_timezone_set($tz);
    }
    // Opcional: locale pt_BR (não é obrigatório e pode depender do servidor)
    if (function_exists('setlocale')) {
        @setlocale(LC_TIME, 'pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil');
    }
}
apply_app_timezone();

/**
 * Retorna datetime atual no fuso do app (string MySQL-friendly).
 */
function now_datetime(): string {
    return date('Y-m-d H:i:s');
}

/**
 * Opcional: aplicar timezone também na sessão do MySQL.
 * Chame logo após $pdo = get_pdo(); se quiser.
 *
 * Obs: 'America/Cuiaba' nem sempre está carregado nas tabelas de timezone do MySQL.
 * Então usamos offset -04:00 (Cuiabá geralmente é UTC-4).
 */
function pdo_apply_timezone(PDO $pdo): void {
    try {
        $pdo->exec("SET time_zone = '-04:00'");
    } catch (Throwable $e) {
        // Se falhar, não quebra o sistema (apenas ignora)
    }
}

/**
 * ========= AUTENTICAÇÃO / SESSÃO =========
 */

function is_logged_in(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['company_id']);
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_admin(): void
{
    if (!is_logged_in() || !is_admin()) {
        http_response_code(403);
        echo 'Acesso restrito.';
        exit;
    }
}

function current_company_id(): ?int
{
    return isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;
}

function current_company_logo(): string
{
    return (string)($_SESSION['company_logo'] ?? '');
}

function current_theme(): string
{
    return (string)($_SESSION['theme'] ?? 'light');
}

function current_user_name(): string
{
    return (string)($_SESSION['nome'] ?? '');
}

/**
 * ========= LOG / AUDITORIA =========
 * IMPORTANTE: gravar created_at pelo PHP (no timezone Cuiabá),
 * para evitar diferença quando o MySQL está em UTC.
 */
function log_action(PDO $pdo, int $companyId, int $userId, string $action, string $details = ''): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO action_logs (company_id, user_id, action, details, created_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$companyId, $userId, $action, $details, now_datetime()]);
}

function user_can_admin(): bool
{
    return is_admin();
}

/**
 * ========= FLASH MESSAGES =========
 */

function flash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string
{
    if (isset($_SESSION['flash'][$key])) {
        $msg = (string)$_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

/**
 * ========= UTILITÁRIOS BÁSICOS =========
 */

function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function format_currency($value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

/**
 * Formata datetime (Y-m-d H:i:s) para BR (d/m/Y H:i).
 * Útil para exibir no painel.
 */
function format_datetime_br(?string $dt): string
{
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return $dt;
    return date('d/m/Y H:i', $ts);
}

function redirect(string $path): void
{
    // Se for URL absoluta, redireciona direto
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path);
        exit;
    }

    // Garante que caminhos relativos respeitem o BASE_URL
    if (!str_starts_with($path, BASE_URL)) {
        $path = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    header('Location: ' . $path);
    exit;
}

/**
 * ========= IMAGENS / UPLOADS =========
 */

/**
 * Monta URL pública de imagem a partir de um caminho salvo no banco.
 *
 * Entende:
 *  - "products/img.jpg"
 *  - "uploads/img.jpg"
 *  - "/uploads/img.jpg"
 *  - "uploads/products/x.jpg"
 *  - URL http/https → retorna como veio
 */
function image_url(?string $path): string
{
    if (!$path) {
        return '';
    }

    // Já é URL completa?
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    // Remove barra inicial
    $clean = ltrim($path, '/');

    // Se começar com "uploads/", remove esse prefixo, vamos normalizar
    if (str_starts_with($clean, 'uploads/')) {
        $clean = substr($clean, strlen('uploads/'));
    }

    // Sempre monta em cima de /uploads
    return rtrim(UPLOAD_URL, '/') . '/' . $clean;
}

/**
 * Upload otimizado de imagem.
 *
 * - $fieldName: nome do campo do formulário (ex: "imagem" ou "logo")
 * - $folder: subpasta dentro de /uploads (ex: "products", "logos" ou "uploads/products")
 * - Retorna: "uploads/products/arquivo.jpg" para salvar no banco
 * - Retorna null em caso de falha
 */
function upload_image_optimized(
    string $fieldName,
    string $folder = 'uploads',
    int $maxSizeMB = MAX_UPLOAD_SIZE_MB
): ?string {
    if (!isset($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];

    // Nenhum arquivo enviado
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    // Outro erro qualquer
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return null;
    }

    // Tamanho
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return null;
    }

    $maxBytes = $maxSizeMB * 1024 * 1024;
    if ($size > $maxBytes) {
        return null;
    }

    // Extensão permitida
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];

    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        return null;
    }

    // Pasta física alvo: UPLOAD_DIR/<folder>
    $relativeFolder = trim($folder, '/'); // pode vir "products" OU "uploads/products"

    // Normaliza para SEM "uploads/" na frente
    if (str_starts_with($relativeFolder, 'uploads/')) {
        $relativeFolder = substr($relativeFolder, strlen('uploads/'));
    }

    $targetDir = rtrim(UPLOAD_DIR, '/') . '/' . $relativeFolder;

    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    // Nome único
    $filename   = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return null;
    }

    // Caminho salvo no banco SEM barra inicial, sempre começando com "uploads/"
    // Ex.: "uploads/products/arquivo.jpg"
    return 'uploads/' . $relativeFolder . '/' . $filename;
}
