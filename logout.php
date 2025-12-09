<?php
require_once __DIR__ . '/helpers.php';
session_destroy();
header('Location: /login.php');
exit;
