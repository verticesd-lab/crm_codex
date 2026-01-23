<?php
require_once __DIR__ . '/config.php';

$base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
header('Location: ' . $base . '/analytics.php', true, 302);
exit;
