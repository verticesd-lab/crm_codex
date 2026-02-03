<?php
// analytics_tracker.php
// Compatível com a tabela: site_visits (company_id, visitor_hash, page, origin, referrer, created_at)

/**
 * Gera/recupera um visitor_id persistente (cookie).
 * IMPORTANTE: precisa ser chamado antes de qualquer output HTML.
 */
function analytics_get_visitor_id(): string
{
    $cookieName = 'crm_vid';

    if (!empty($_COOKIE[$cookieName]) && is_string($_COOKIE[$cookieName]) && strlen($_COOKIE[$cookieName]) >= 16) {
        return $_COOKIE[$cookieName];
    }

    $vid = bin2hex(random_bytes(16)); // 32 chars

    // 365 dias
    setcookie($cookieName, $vid, [
        'expires'  => time() + (365 * 24 * 60 * 60),
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[$cookieName] = $vid;
    return $vid;
}

/**
 * Retorna um origin simples e útil:
 * - se tiver utm_source => usa utm_source
 * - senão, se tiver referer => "referral"
 * - senão => "direct"
 */
function analytics_get_origin(): ?string
{
    $utm = trim((string)($_GET['utm_source'] ?? ''));
    if ($utm !== '') return substr($utm, 0, 50);

    $origin = trim((string)($_GET['origin'] ?? ''));
    if ($origin !== '') return substr($origin, 0, 50);

    $ref = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($ref !== '') return 'referral';

    return 'direct';
}

/**
 * Registra 1 pageview na tabela site_visits.
 * - visitor_hash: hash sha256 do cookie (não salva cookie puro)
 * - referrer: guarda o referer (limitado)
 * - origin: direct/referral/utm_source
 */
function track_page_view(PDO $pdo, int $companyId, string $pageKey): void
{
    try {
        if ($companyId <= 0) return;

        $vid = analytics_get_visitor_id();
        $visitorHash = hash('sha256', $vid);

        $pageKey = trim($pageKey);
        if ($pageKey === '') $pageKey = '/';

        $origin = analytics_get_origin();
        $referrer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        $referrer = $referrer !== '' ? substr($referrer, 0, 255) : null;

        $stmt = $pdo->prepare('
            INSERT INTO site_visits (company_id, visitor_hash, page, origin, referrer, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $companyId,
            $visitorHash,
            substr($pageKey, 0, 255),
            $origin,
            $referrer
        ]);
    } catch (Throwable $e) {
        // tracking nunca pode derrubar a página
    }
}
