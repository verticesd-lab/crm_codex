<?php
require_once __DIR__ . '/config.php';

/**
 * Inicia sessão com segurança:
 * - só inicia se ainda não iniciou
 * - e só inicia se ainda não enviou headers (pra evitar "headers already sent")
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
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
function reactivation_policy(): array {
    return [
        'max_lotes_per_day'            => 3,
        'max_contacts_per_day'         => 90,
        'min_gap_between_lots_seconds' => 3600,
        'send_delay_min_seconds'       => 180,
        'send_delay_max_seconds'       => 320,
    ];
}

function reactivation_today_bounds(): array {
    $appTz = new DateTimeZone(app_timezone());
    $utcTz = new DateTimeZone('UTC');

    $nowLocal   = new DateTime('now', $appTz);
    $startLocal = (clone $nowLocal)->setTime(0, 0, 0);
    $endLocal   = (clone $startLocal)->modify('+1 day');
    $startUtc   = (clone $startLocal)->setTimezone($utcTz);
    $endUtc     = (clone $endLocal)->setTimezone($utcTz);

    return [
        'now_local'   => $nowLocal,
        'start_local' => $startLocal,
        'end_local'   => $endLocal,
        'start_utc'   => $startUtc,
        'end_utc'     => $endUtc,
    ];
}

function reactivation_availability(PDO $pdo, int $companyId, int $plannedContacts = 0): array {
    $policy = reactivation_policy();
    $bounds = reactivation_today_bounds();

    $usage = $pdo->prepare("
        SELECT
            COALESCE(SUM(
                CASE
                    WHEN status = 'cancelado' AND COALESCE(enviados,0) + COALESCE(erros,0) = 0 THEN 0
                    ELSE 1
                END
            ), 0) AS lotes_usados,
            COALESCE(SUM(
                CASE
                    WHEN status = 'cancelado' THEN LEAST(COALESCE(total_clientes,0), COALESCE(enviados,0) + COALESCE(erros,0))
                    ELSE COALESCE(total_clientes,0)
                END
            ), 0) AS contatos_usados
        FROM reativacao_lotes
        WHERE company_id = ?
          AND criado_em >= ?
          AND criado_em < ?
    ");
    $usage->execute([
        $companyId,
        $bounds['start_utc']->format('Y-m-d H:i:s'),
        $bounds['end_utc']->format('Y-m-d H:i:s'),
    ]);
    $usageRow = $usage->fetch(PDO::FETCH_ASSOC) ?: [];

    $dailyLotesUsed    = (int)($usageRow['lotes_usados'] ?? 0);
    $dailyContactsUsed = (int)($usageRow['contatos_usados'] ?? 0);

    $result = [
        'can_send'            => true,
        'reason'              => null,
        'next_at_local'       => null,
        'next_at_br'          => null,
        'daily_lotes_used'    => $dailyLotesUsed,
        'daily_contacts_used' => $dailyContactsUsed,
        'remaining_lotes'     => max(0, $policy['max_lotes_per_day'] - $dailyLotesUsed),
        'remaining_contacts'  => max(0, $policy['max_contacts_per_day'] - $dailyContactsUsed),
        'policy'              => $policy,
    ];

    $lotesAfterCreate    = $dailyLotesUsed + ($plannedContacts > 0 ? 1 : 0);
    $contactsAfterCreate = $dailyContactsUsed + max(0, $plannedContacts);

    if ($dailyLotesUsed >= $policy['max_lotes_per_day'] || $lotesAfterCreate > $policy['max_lotes_per_day']) {
        $result['can_send']      = false;
        $result['reason']        = 'limite_lotes_dia';
        $result['next_at_local'] = $bounds['end_local']->format('Y-m-d H:i:s');
        $result['next_at_br']    = $bounds['end_local']->format('d/m/Y H:i');
        return $result;
    }

    if ($dailyContactsUsed >= $policy['max_contacts_per_day'] || $contactsAfterCreate > $policy['max_contacts_per_day']) {
        $result['can_send']      = false;
        $result['reason']        = 'limite_contatos_dia';
        $result['next_at_local'] = $bounds['end_local']->format('Y-m-d H:i:s');
        $result['next_at_br']    = $bounds['end_local']->format('d/m/Y H:i');
        return $result;
    }

    $latest = $pdo->prepare("
        SELECT COALESCE(concluido_em, iniciado_em, criado_em) AS referencia_em
        FROM reativacao_lotes
        WHERE company_id = ?
          AND NOT (status = 'cancelado' AND COALESCE(enviados,0) + COALESCE(erros,0) = 0)
        ORDER BY COALESCE(concluido_em, iniciado_em, criado_em) DESC
        LIMIT 1
    ");
    $latest->execute([$companyId]);
    $lastReference = $latest->fetchColumn();

    if ($lastReference) {
        $lastLocal = db_datetime_to_app((string)$lastReference);
        if ($lastLocal instanceof DateTime) {
            $nextAllowed = (clone $lastLocal)->modify('+' . $policy['min_gap_between_lots_seconds'] . ' seconds');
            if ($nextAllowed > $bounds['now_local']) {
                $result['can_send']      = false;
                $result['reason']        = 'intervalo_entre_lotes';
                $result['next_at_local'] = $nextAllowed->format('Y-m-d H:i:s');
                $result['next_at_br']    = $nextAllowed->format('d/m/Y H:i');
            }
        }
    }

    return $result;
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id'], $_SESSION['company_id']);
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        // se já enviou headers, não dá pra redirecionar
        if (headers_sent()) {
            http_response_code(401);
            echo 'Sessão expirada. Faça login novamente.';
            exit;
        }
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
function sanitize($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function format_currency($value): string {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function redirect(string $path): void {
    // Se for URL absoluta, redireciona direto
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        if (!headers_sent()) {
            header('Location: ' . $path);
        } else {
            echo '<script>location.href=' . json_encode($path) . ';</script>';
        }
        exit;
    }

    // Garante que caminhos relativos respeitem o BASE_URL
    if (!str_starts_with($path, BASE_URL)) {
        $path = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    if (!headers_sent()) {
        header('Location: ' . $path);
    } else {
        echo '<script>location.href=' . json_encode($path) . ';</script>';
    }
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
