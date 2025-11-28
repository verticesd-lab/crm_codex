<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
    return $_SESSION['company_id'] ?? null;
}

function current_company_logo(): string
{
    return $_SESSION['company_logo'] ?? '';
}

function current_theme(): string
{
    return $_SESSION['theme'] ?? 'light';
}

function current_user_name(): string
{
    return $_SESSION['nome'] ?? '';
}

/**
 * ========= LOG / AUDITORIA =========
 */

function log_action(PDO $pdo, int $companyId, int $userId, string $action, string $details = ''): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO action_logs (company_id, user_id, action, details, created_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$companyId, $userId, $action, $details]);
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
        $msg = $_SESSION['flash'][$key];
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
 * - $folder: subpasta dentro de /uploads (ex: "products", "logos")
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
    if ($file['error'] !== UPLOAD_ERR_OK) {
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
    $relativeFolder = trim($folder, '/'); // ex: "products"
    $targetDir      = rtrim(UPLOAD_DIR, '/') . '/' . $relativeFolder;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
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
