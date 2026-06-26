<?php
// PHP built-in server may not forward Authorization; normalize it manually.
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    // Already available.
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    if (isset($headers['Authorization'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];
    }
} elseif (function_exists('getallheaders')) {
    $all = getallheaders();
    foreach ($all as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $_SERVER['HTTP_AUTHORIZATION'] = $v;
            break;
        }
    }
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Rota da API — passa para o index.php da API
if (str_starts_with($uri, '/api/v1/')) {
    $_SERVER['PATH_INFO'] = $uri;
    require __DIR__ . '/api/v1/index.php';
    return true;
}

// Arquivo físico existe — serve normalmente
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Qualquer outra rota — comportamento padrão do CRM
$file = __DIR__ . $uri;
if (file_exists($file . '.php')) {
    require $file . '.php';
    return true;
}

return false;
