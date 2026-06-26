<?php
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