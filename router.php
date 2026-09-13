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

function router_env(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === null) {
        $value = $_ENV[$key] ?? ($_SERVER[$key] ?? '');
    }

    return trim((string)$value);
}

function router_hostname(string $value): string
{
    $value = trim(explode(',', $value)[0]);
    if ($value === '') return '';

    if (!str_contains($value, '://')) {
        $value = 'http://' . $value;
    }

    return strtolower(rtrim((string)(parse_url($value, PHP_URL_HOST) ?: ''), '.'));
}

function router_host_authority(string $value): string
{
    $value = trim(explode(',', $value)[0]);
    if ($value === '') return '';

    if (!str_contains($value, '://')) {
        $value = 'http://' . $value;
    }

    $host = (string)(parse_url($value, PHP_URL_HOST) ?: '');
    if ($host === '') return '';

    $port = parse_url($value, PHP_URL_PORT);
    return $host . ($port !== null ? ':' . $port : '');
}

function router_query_value(array $query, string $key): string
{
    $value = $query[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function router_redirect(string $path, array $query = []): never
{
    $queryString = http_build_query($query);
    header('Location: ' . $path . ($queryString !== '' ? '?' . $queryString : ''), true, 301);
    exit;
}

function router_request_scheme(): string
{
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'https') return 'https';

    $cfVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
    if (is_array($cfVisitor) && strtolower((string)($cfVisitor['scheme'] ?? '')) === 'https') return 'https';

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https';
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return 'https';

    return 'http';
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) && $requestPath !== '' ? $requestPath : '/';
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$publicCompanySlug = router_env('PUBLIC_COMPANY_SLUG');

$publicHostValue = router_env('PUBLIC_HOST');
$publicHost = router_hostname($publicHostValue);
$adminHost = router_hostname(router_env('ADMIN_HOST'));
$legacyHost = router_hostname(router_env('LEGACY_HOST'));
$requestHost = router_hostname((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
$hostRoutingEnabled = $publicHost !== '' && $adminHost !== '' && $legacyHost !== '';
$publicAuthority = router_host_authority($publicHostValue);
$publicRedirectBase = $hostRoutingEnabled
    && $publicAuthority !== ''
    && in_array($requestHost, [$adminHost, $legacyHost], true)
        ? router_request_scheme() . '://' . $publicAuthority
        : '';

// Hosts desconhecidos continuam aptos para preview (por exemplo, sslip.io).
$isPreviewHost = $hostRoutingEnabled
    && !in_array($requestHost, [$publicHost, $adminHost, $legacyHost], true);
$servesPublicRoutes = !$hostRoutingEnabled || $requestHost === $publicHost || $isPreviewHost;

// Rota da API - passa para o index.php da API sem alterar Authorization.
if (str_starts_with($requestPath, '/api/v1/')) {
    $_SERVER['PATH_INFO'] = $requestPath;
    require __DIR__ . '/api/v1/index.php';
    return true;
}

// URLs tecnicas antigas continuam validas para outras empresas e para POSTs.
if ($requestMethod === 'GET' && $publicCompanySlug !== '') {
    if ($requestPath === '/landing.php') {
        $requestedCompany = router_query_value($_GET, 'empresa');
        if ($requestedCompany === '' || hash_equals($publicCompanySlug, $requestedCompany)) {
            $query = $_GET;
            unset($query['empresa']);
            router_redirect($publicRedirectBase . '/', $query);
        }
    }

    $legacyPublicRoutes = [
        '/loja.php' => '/loja',
        '/agenda.php' => '/agenda',
    ];

    if (isset($legacyPublicRoutes[$requestPath])) {
        $requestedCompany = router_query_value($_GET, 'empresa');
        if ($requestedCompany !== '' && hash_equals($publicCompanySlug, $requestedCompany)) {
            $query = $_GET;
            unset($query['empresa']);
            router_redirect($publicRedirectBase . $legacyPublicRoutes[$requestPath], $query);
        }
    }
}

// Tabela explicita das unicas rotas publicas amigaveis.
$publicRoutes = [
    '/' => 'landing.php',
    '/loja' => 'loja.php',
    '/agenda' => 'agenda.php',
];

if (
    $requestMethod === 'GET'
    && $servesPublicRoutes
    && isset($publicRoutes[$requestPath])
    && ($requestPath !== '/' || $publicCompanySlug !== '')
) {
    $requestedCompany = router_query_value($_GET, 'empresa');
    if (
        $requestPath === '/'
        && $requestedCompany !== ''
        && !hash_equals($publicCompanySlug, $requestedCompany)
    ) {
        return false;
    }

    if ($publicCompanySlug !== '' && ($requestedCompany === '' || hash_equals($publicCompanySlug, $requestedCompany))) {
        $_GET['empresa'] = $publicCompanySlug;
    }

    require __DIR__ . '/' . $publicRoutes[$requestPath];
    return true;
}

// Arquivos fisicos (assets, uploads, PHP legado etc.) sao servidos normalmente.
if ($requestPath !== '/' && file_exists(__DIR__ . $requestPath)) {
    return false;
}

// Sem slug publico configurado, preserva integralmente o fallback legado.
if ($publicCompanySlug === '') {
    $legacyFile = __DIR__ . $requestPath;
    if (file_exists($legacyFile . '.php')) {
        require $legacyFile . '.php';
        return true;
    }
}

// Na raiz sem slug publico, o servidor continua entregando o index.php legado.
return false;
