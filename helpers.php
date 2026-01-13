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
 * ========= TIMEZONES (CORRETO) =========
 * APP_TZ = fuso da aplicação (exibição)
 * DB_TZ  = fuso padrão do banco (recomendado: UTC)
 */
function app_timezone(): string { return 'America/Cuiaba'; }
function db_timezone(): string  { return 'UTC'; }

/**
 * Aplica timezone do app no PHP (para date(), DateTime sem tz, etc).
 */
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
 * Retorna datetime atual em UTC (para salvar no banco de forma consistente).
 */
function now_utc_datetime(): string {
    $d = new DateTime('now', new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s');
}

/**
 * Retorna datetime atual no fuso do app (apenas se precisar exibir/logar local).
 */
function now_app_datetime(): string {
    $d = new DateTime('now', new DateTimeZone(app_timezone()));
    return $d->format('Y-m-d H:i:s');
}

/**
 * Define timezone da sessão do MySQL.
 * RECOMENDADO: manter em UTC (+00:00).
 *
 * Chame após $pdo = get_pdo(); (1x por request) se quiser forçar.
 */
function pdo_apply_timezone(PDO $pdo, string $tz = '+00:00'): void {
    try {
        $pdo->exec("SET time_zone = " . $pdo->quote($tz));
    } catch (Throwable $e) {
        // Ignora
    }
}

/**
 * ========= CONVERSÃO DE DATETIME DO BANCO =========
 * Converte um datetime (assumido como DB_TZ/UTC) para o TZ do app (Cuiabá).
 */
function db_datetime_to_app(?string $dt, ?string $dbTz = null): ?DateTime {
    if (!$dt) return null;

    $dt = trim($dt);
    if ($dt === '') return null;

    $dbTz = $dbTz ?: db_timezone();

    try {
        // Se vier só data (Y-m-d), completa
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
            $dt .= ' 00:00:00';
        }

        // Normaliza ISO (T, milissegundos, Z)
        $normalized = str_replace('T', ' ', $dt);
        $normalized = preg_replace('/\.\d+/', '', $normalized);
        $normalized = preg_replace('/Z$/', '', $normalized);

        $dbZone  = new DateTimeZone($dbTz);
        $appZone = new DateTimeZone(app_timezone());

        $d = new DateTime($normalized, $dbZone);
        $d->setTimezone($appZone);

        return $d;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Formata datetime do banco para BR (d/m/Y H:i) já convertido para TZ do app.
 * Por padrão assume que o banco está em UTC.
 */
function format_datetime_br(?string $dt, ?string $dbTz = null): string {
    $d = db_datetime_to_app($dt, $dbTz);
    if (!$d) return $dt ? (string)$dt : '';
    return $d->format('d/m/Y H:i');
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
 * Recomendado: salvar em UTC no banco.
 */
function log_action(PDO $pdo, int $companyId, int $userId, string $action, string $details = ''): void {
    $stmt = $pdo->prepare(
        'INSERT INTO action_logs (company_id, user_id, action, details, created_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$companyId, $userId, $action, $details, now_utc_datetime()]);
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

function redirect(string $path): void {
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
 * Monta URL pública de imagem a partir de um caminho salvo no banco.
 */
function image_url(?string $path): string {
    if (!$path) return '';

    // Já é URL completa?
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    $clean = ltrim($path, '/');

    // Normaliza removendo "uploads/" do começo, pois UPLOAD_URL já aponta para /uploads
    if (str_starts_with($clean, 'uploads/')) {
        $clean = substr($clean, strlen('uploads/'));
    }

    return rtrim(UPLOAD_URL, '/') . '/' . $clean;
}

/**
 * Upload otimizado de imagem.
 * - Retorna "uploads/pasta/arquivo.ext" para salvar no banco
 */
function upload_image_optimized(
    string $fieldName,
    string $folder = 'uploads',
    int $maxSizeMB = MAX_UPLOAD_SIZE_MB
): ?string {
    if (!isset($_FILES[$fieldName])) return null;

    $file = $_FILES[$fieldName];

    // Nenhum arquivo enviado
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;

    // Erro qualquer
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

    // Tamanho
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) return null;

    $maxBytes = $maxSizeMB * 1024 * 1024;
    if ($size > $maxBytes) return null;

    // Extensão permitida
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if ($ext === '' || !in_array($ext, $allowedExt, true)) return null;

    // Pasta física alvo: UPLOAD_DIR/<folder>
    $relativeFolder = trim($folder, '/'); // pode vir "products" ou "uploads/products"
    if (str_starts_with($relativeFolder, 'uploads/')) {
        $relativeFolder = substr($relativeFolder, strlen('uploads/'));
    }

    $targetDir = rtrim(UPLOAD_DIR, '/') . '/' . $relativeFolder;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0777, true);
    }

    // Nome único
    $filename = time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return null;
    }

    // Caminho salvo no banco
    return 'uploads/' . $relativeFolder . '/' . $filename;
}
