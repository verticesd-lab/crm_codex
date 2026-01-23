<?php
// /site_analytics.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Gera/recupera um cookie anônimo para visitante (sem dados pessoais).
 * Expira em 24h (contagem de "únicos por dia").
 */
function analytics_get_visitor_cookie_id(): string
{
    $cookieName = 'vid';
    if (!empty($_COOKIE[$cookieName])) {
        return (string)$_COOKIE[$cookieName];
    }

    $id = bin2hex(random_bytes(16)); // 32 chars
    // 24h
    setcookie($cookieName, $id, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Também retorna na mesma request
    $_COOKIE[$cookieName] = $id;
    return $id;
}

/**
 * Detecta origem simples via utm_source/referrer.
 */
function analytics_detect_origin(): ?string
{
    $utm = strtolower(trim((string)($_GET['utm_source'] ?? '')));
    if ($utm !== '') {
        if (str_contains($utm, 'instagram')) return 'instagram';
        if (str_contains($utm, 'facebook') || str_contains($utm, 'fb')) return 'facebook';
        if (str_contains($utm, 'whatsapp') || str_contains($utm, 'wa')) return 'whatsapp';
        if (str_contains($utm, 'google')) return 'google';
        return $utm;
    }

    $ref = strtolower(trim((string)($_SERVER['HTTP_REFERER'] ?? '')));
    if ($ref === '') return 'direct';
    if (str_contains($ref, 'instagram.com')) return 'instagram';
    if (str_contains($ref, 'facebook.com') || str_contains($ref, 'fb.com')) return 'facebook';
    if (str_contains($ref, 't.co') || str_contains($ref, 'twitter.com') || str_contains($ref, 'x.com')) return 'twitter';
    if (str_contains($ref, 'google.')) return 'google';

    return 'other';
}

/**
 * Track de pageview (página pública).
 * - Não salva IP puro (LGPD): usa hash
 * - visitor_hash muda diariamente por causa do cookie que expira em 24h
 */
function track_site_visit(int $companyId, ?string $page = null): void
{
    if ($companyId <= 0) return;

    // Evita track em páginas internas do CRM por acidente
    $uri = $page ?? (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') return;

    // Se quiser excluir assets:
    if (preg_match('#\.(css|js|png|jpg|jpeg|webp|svg|ico)$#i', $uri)) return;

    $cookieId = analytics_get_visitor_cookie_id();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $visitorHash = hash('sha256', $cookieId); // único por cookie (24h)
    $ipHash = $ip !== '' ? hash('sha256', $ip) : '';

    $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $origin = analytics_detect_origin();

    // Limita tamanho
    $pageDb = substr($uri, 0, 255);
    $refDb  = $ref !== '' ? substr($ref, 0, 255) : null;
    $uaDb   = $ua !== '' ? substr($ua, 0, 255) : null;

    try {
        $pdo = get_pdo();
        $st = $pdo->prepare('
            INSERT INTO site_visits (company_id, visitor_hash, page, origin, referrer, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $st->execute([$companyId, $visitorHash, $pageDb, $origin, $refDb]);
    } catch (Throwable $e) {
        // silencioso (não quebra a página pública)
    }
}
