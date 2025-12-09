<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
        header('Location: /login.php');
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

function log_action(PDO $pdo, int $companyId, int $userId, string $action, string $details = ''): void
{
    $stmt = $pdo->prepare('INSERT INTO action_logs (company_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$companyId, $userId, $action, $details]);
}

function user_can_admin(): bool
{
    return is_admin();
}

function current_user_name(): string
{
    return $_SESSION['nome'] ?? '';
}

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
    header('Location: ' . $path);
    exit;
}
