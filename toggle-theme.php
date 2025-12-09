<?php
require_once __DIR__ . '/helpers.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current = $_SESSION['theme'] ?? 'light';
$_SESSION['theme'] = $current === 'dark' ? 'light' : 'dark';
$back = $_SERVER['HTTP_REFERER'] ?? '/index.php';
header('Location: ' . $back);
exit;
