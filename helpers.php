<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ========= COMPAT (PHP < 8) =========
 */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

/**
 * ========= TIMEZONE (Cuiabá/MT) =========
 * Cuiabá = America/Cuiaba
 * Ajusta todas as datas geradas pelo PHP (date(), time(), DateTime(), etc.)
 */
function app_timezone(): string {
    return 'America/Cuiaba';
}

function apply_app_timezone(): void {
    $tz = app_timezone();

    if (function_exists('date_default_timezone_set')) {
        date_default_timezone_set($tz);
    }

    // Opcional (depende do servidor ter locale instalado)
    if (function_exists('setlocale')) {
        @setlocale(LC_TIME, 'pt_BR.UTF-8', 'pt_BR', 'Portuguese_Brazil');
    }
}
apply_app_timezone();

/**
 * Retorna datetime atual no fuso do app (string MySQL-friendly).
 */
function now_datetime(): string {
    return (new DateTime('now', new DateTimeZone(app_timezone())))->format('Y-m-d H:i:s');
}

/**
 * Opcional: aplicar timezone também na sessão do MySQL.
 * Chame logo após $pdo = get_pdo(); (1x por request).
 *
 * Obs: MySQL nem sempre tem "America/Cuiaba" carregado nas tabelas.
 * Por isso usamos offset fixo: -04:00
 */
function pdo_apply_timezone(PDO $pdo): void {
    try {
        $pdo->exec("SET time_zone = '-04:00'");
    } catch (Throwable $e) {
        // Ignora se falhar
    }
}

/**
 * ========= AUTENTICAÇÃO / SESSÃO =========
 */
function is_logged_in(): bool {
    return isset($_SESSION['user_id'], $_SESSION['company_id']);
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_admin(): void {
    if (!is_logged_in() || !is_admin()) {
        http_response_code(403);
        echo 'Acesso restrito.';
        exit;
    }
}

function current_company_id(): ?int {
    return isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;
}

function current_company_logo(): string {
    return (string)($_SESSION['company_logo'] ?? '');
}

function current_theme(): string {
    return (string)($_SESSION['theme'] ?? 'light');
}

function current_user_name(): string {
    return (string)($_SESSION['nome'] ?? '');
}

/**
 * ========= LOG / AUDITORIA =========
 * Salva created_at pelo PHP (timezone Cuiabá) para não depender do MySQL em UTC.
 */
function log_action(PDO $pdo, int $companyId, int $userId, string $action, string $details = ''): void {
    $stmt = $pdo->prepare(
        'INSERT INTO action_logs (company_id, user_id, action, details, created_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$companyId, $userId, $action, $details, now_datetime()]);
}

function user_can_admin(): bool {
    return is_admin();
}

/**
 * ========= FLASH MESSAGES =========
 */
function flash(string $key, string $message): void {
    $_SESSION['flash'][$key] = $message;
}

function get_flash(string $key): ?string {
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
function sanitize(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function format_currency($value): string {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

/**
 * Formata datetime (Y-m-d H:i:s) para BR (d/m/Y H:i) já no TZ do app.
 */
function format_datetime_br(?string $dt): string {
    if (!$dt) return '';
    try {
        $d = new DateTime($dt, new DateTimeZone(app_timezone()));
        return $d->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string)$dt;
    }
}

function redirect(string $path): void {
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path);
        exit;
    }

    if (!str_starts_with($path, BASE_URL)) {
        $path = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    header('Location: ' . $path);
    exit;
}

/**
 * ========= IMAGENS / UPLOADS =========
 */
function image_url(?string $path): string {
    if (!$path) return '';

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    $clean = ltrim($path, '/');

    if (str_starts_with($clean, 'uploads/')) {
        $clean = substr($clean, strlen('uploads/'));
    }

    return rtrim(UPLOAD_URL, '/') . '/' . $clean;
}

function upload_image_optimized(
    string $fieldName,
    string $folder = 'uploads',
    int $maxSizeMB = MAX_UPLOAD_SIZE_MB
): ?string {
    if (!isset($_FILES[$fieldName])) return null;

    $file = $_FILES[$fieldName];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) return null;

    $maxBytes = $maxSizeMB * 1024 * 1024;
    if ($size > $maxBytes) return null;

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if ($ext === '' || !in_array($ext, $allowedExt, true)) return null;

    $relativeFolder = trim($folder, '/');
    if (str_starts_with($relativeFolder, 'uploads/')) {
        $relativeFolder = substr($relativeFolder, strlen('uploads/'));
    }

    $targetDir = rtrim(UPLOAD_DIR, '/') . '/' . $relativeFolder;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    $filename = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return null;
    }

    return 'uploads/' . $relativeFolder . '/' . $filename;
}
