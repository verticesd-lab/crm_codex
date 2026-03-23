<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/analytics_tracker.php';
require_once __DIR__ . '/db.php';

function lp_env(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === null) {
        $value = $_ENV[$key] ?? ($_SERVER[$key] ?? '');
    }

    return trim((string)$value);
}

function lp_escape(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function lp_public_url(string $path = ''): string
{
    $base = rtrim((string)BASE_URL, '/');
    $clean = ltrim($path, '/');

    if ($base === '') {
        return $clean;
    }

    return $clean === '' ? $base : $base . '/' . $clean;
}

function lp_asset_url(array $candidates): string
{
    foreach ($candidates as $candidate) {
        $relative = trim(str_replace('\\', '/', (string)$candidate), '/');
        if ($relative === '') {
            continue;
        }

        $absolute = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            return lp_public_url($relative);
        }
    }

    return '';
}

function lp_excerpt(?string $text, int $limit = 80): string
{
    $value = trim((string)$text);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $limit, '...');
    }

    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, max(0, $limit - 3)) . '...';
}

function lp_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');

    return $value !== '' ? $value : 'produto';
}

function lp_whatsapp_link(string $number, string $message): string
{
    $cleanNumber = preg_replace('/\D+/', '', $number) ?: '';
    return 'https://wa.me/' . $cleanNumber . '?text=' . rawurlencode($message);
}

$company = [
    'id' => 1,
    'slug' => '',
    'nome_fantasia' => 'For Men Store',
    'razao_social' => 'FORMEN MULTIMARCAS',
    'whatsapp_principal' => '5565999397274',
    'instagram_usuario' => 'formenstore_oficial',
    'logo' => '',
];

$company_id = (int)$company['id'];
$company_slug = trim((string)($_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '')));
$pdo = null;
$produtos_destaque = [];

try {
    $pdo = get_pdo();

    $selectCompanySql = 'SELECT id, slug, nome_fantasia, razao_social, whatsapp_principal, instagram_usuario, logo FROM companies';
    $companyRow = null;

    if ($company_slug !== '') {
        $stmt = $pdo->prepare($selectCompanySql . ' WHERE slug = ? LIMIT 1');
        $stmt->execute([$company_slug]);
        $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($companyRow === null && function_exists('current_company_id')) {
        $sessionCompanyId = (int)(current_company_id() ?? 0);
        if ($sessionCompanyId > 0) {
            $stmt = $pdo->prepare($selectCompanySql . ' WHERE id = ? LIMIT 1');
            $stmt->execute([$sessionCompanyId]);
            $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    if ($companyRow === null) {
        $stmt = $pdo->prepare($selectCompanySql . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$company_id]);
        $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($companyRow === null) {
        $companyRow = $pdo->query($selectCompanySql . ' ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (is_array($companyRow)) {
        $company = array_merge($company, $companyRow);
        $company_id = (int)($companyRow['id'] ?? $company_id);
        $company_slug = trim((string)($companyRow['slug'] ?? $company_slug));
    }

    track_page_view($pdo, $company_id, 'landing');

    $stmt = $pdo->prepare('
        SELECT id, nome, descricao, preco, categoria, tipo, imagem
        FROM products
        WHERE company_id = ?
          AND ativo = 1
          AND destaque = 1
        ORDER BY nome ASC
        LIMIT 3
    ');
    $stmt->execute([$company_id]);
    $produtos_destaque = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $pdo = null;
    $produtos_destaque = [];
}

$companyName = trim((string)($company['nome_fantasia'] ?? 'For Men Store')) ?: 'For Men Store';
$companyLegalName = trim((string)($company['razao_social'] ?? 'FORMEN MULTIMARCAS')) ?: 'FORMEN MULTIMARCAS';
$instagramUser = ltrim(trim((string)($company['instagram_usuario'] ?? 'formenstore_oficial')), '@');
if ($instagramUser === '') {
    $instagramUser = 'formenstore_oficial';
}

$instagramUrl = 'https://instagram.com/' . $instagramUser;
$whatsapp_number = preg_replace('/\D+/', '', (string)($company['whatsapp_principal'] ?? '5565999397274')) ?: '5565999397274';

$whatsapp_link = lp_whatsapp_link($whatsapp_number, 'Ola! Vi o anuncio da For Men Store e quero saber mais.');
$catalogWhatsAppLink = lp_whatsapp_link($whatsapp_number, 'Ola! Quero ver o catalogo completo da For Men Store.');
$barberWhatsAppLink = lp_whatsapp_link($whatsapp_number, 'Ola! Quero agendar na barbearia da For Men Store.');

$catalogUrl = $catalogWhatsAppLink;
$catalogUsesStore = false;
if ($company_slug !== '') {
    $catalogUrl = lp_public_url('loja.php') . '?empresa=' . rawurlencode($company_slug);
    $catalogUsesStore = true;
}

$heroImageUrl = lp_asset_url([
    'assets/images/lp-hero.jpg',
    'assets/images/landing-hero.jpg',
    'assets/images/for-men-hero.jpg',
    'assets/images/fachada.jpg',
    'assets/images/store-front.jpg',
]);

$barberImageUrl = lp_asset_url([
    'assets/images/barbearia.jpg',
    'assets/images/landing-barbearia.jpg',
    'assets/images/barber.jpg',
]);

if ($heroImageUrl === '' && !empty($company['logo'])) {
    $heroImageUrl = image_url((string)$company['logo']);
}

if ($barberImageUrl === '' && !empty($company['logo'])) {
    $barberImageUrl = image_url((string)$company['logo']);
}

$storeAddress = lp_env('LANDING_STORE_ADDRESS');
if ($storeAddress === '') {
    $storeAddress = 'Avenida Goiania, 1913 - Parque Residencial Buriti, Rondonopolis - MT, 78716-090';
}

$mapEmbedUrl = lp_env('LANDING_MAP_EMBED_URL');
if ($mapEmbedUrl === '') {
    $mapEmbedUrl = 'https://www.google.com/maps?q=' . rawurlencode($storeAddress) . '&output=embed';
}

$mapOpenUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($storeAddress);
$metaPixelId = lp_env('META_PIXEL_ID');
if ($metaPixelId === '') {
    $metaPixelId = lp_env('FB_PIXEL_ID');
}

$gaMeasurementId = lp_env('GA_MEASUREMENT_ID');
$googleAdsId = lp_env('GOOGLE_ADS_ID');
$gtagPrimaryId = $gaMeasurementId !== '' ? $gaMeasurementId : $googleAdsId;
$trackCtaUrl = lp_public_url('api/track_cta.php');

$utm_source = substr(trim((string)($_GET['utm_source'] ?? '')), 0, 100);
$utm_medium = substr(trim((string)($_GET['utm_medium'] ?? '')), 0, 100);
$utm_campaign = substr(trim((string)($_GET['utm_campaign'] ?? '')), 0, 120);
$utm_content = substr(trim((string)($_GET['utm_content'] ?? '')), 0, 120);
$hasUtm = $utm_source !== '' || $utm_medium !== '' || $utm_campaign !== '' || $utm_content !== '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="description" content="Moda masculina original, atendimento rapido no WhatsApp e loja fisica pronta para vestir voce da cabeca aos pes.">
  <title>For Men Store - Moda Masculina Original</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if ($metaPixelId !== ''): ?>
  <script>
    !function(f,b,e,v,n,t,s){
      if(f.fbq){return;}
      n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments);};
      if(!f._fbq){f._fbq=n;}
      n.push=n;
      n.loaded=!0;
      n.version='2.0';
      n.queue=[];
      t=b.createElement(e);
      t.async=!0;
      t.src=v;
      s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s);
    }(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', <?= json_encode($metaPixelId) ?>);
    fbq('track', 'PageView');
  </script>
<?php endif; ?>
<?php if ($gtagPrimaryId !== ''): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= lp_escape($gtagPrimaryId) ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
<?php if ($gaMeasurementId !== ''): ?>
    gtag('config', <?= json_encode($gaMeasurementId) ?>);
<?php endif; ?>
<?php if ($googleAdsId !== ''): ?>
    gtag('config', <?= json_encode($googleAdsId) ?>);
<?php endif; ?>
  </script>
<?php endif; ?>
  <style>
    :root {
      --black: #0a0a0a;
      --dark: #111111;
      --card: #1a1a1a;
      --border: #2a2a2a;
      --gold: #c9a84c;
      --gold-light: #e8c96a;
      --white: #f5f5f5;
      --gray: #8d8d8d;
      --green-wa: #25d366;
      --shadow: 0 24px 60px rgba(0, 0, 0, 0.38);
      --font-display: 'Bebas Neue', sans-serif;
      --font-body: 'Barlow', sans-serif;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      background:
        radial-gradient(circle at top, rgba(201, 168, 76, 0.08), transparent 35%),
        linear-gradient(180deg, #0b0b0b 0%, #050505 100%);
      color: var(--white);
      font-family: var(--font-body);
      font-size: 16px;
      line-height: 1.6;
      overflow-x: hidden;
    }
    img { display: block; max-width: 100%; }
    a { color: inherit; text-decoration: none; }
    .container { margin: 0 auto; max-width: 1120px; padding: 0 20px; }
    .text-center { text-align: center; }
    .text-gold { color: var(--gold); }
    .btn {
      align-items: center;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      display: inline-flex;
      font-family: var(--font-body);
      font-size: 1rem;
      font-weight: 700;
      gap: 10px;
      justify-content: center;
      letter-spacing: 0.05em;
      padding: 16px 32px;
      text-transform: uppercase;
      transition: transform .2s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease;
    }
    .btn:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
    .btn:active { transform: translateY(0); }
    .btn-whatsapp { background: var(--green-wa); color: #fff; }
    .btn-whatsapp:hover { background: #1fb855; }
    .btn-outline { background: transparent; border: 2px solid var(--border); color: var(--white); }
    .btn-outline:hover { border-color: var(--gold); color: var(--gold); }
    .btn-gold { background: var(--gold); color: var(--black); }
    .btn-gold:hover { background: var(--gold-light); }
    .badge {
      background: rgba(201, 168, 76, 0.15);
      border: 1px solid rgba(201, 168, 76, 0.28);
      border-radius: 999px;
      color: var(--gold);
      display: inline-block;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.12em;
      padding: 6px 16px;
      text-transform: uppercase;
    }
    .lp-header {
      backdrop-filter: blur(8px);
      background: rgba(10, 10, 10, 0.94);
      border-bottom: 1px solid var(--border);
      left: 0;
      padding: 12px 0;
      position: fixed;
      right: 0;
      top: 0;
      z-index: 999;
    }
    .lp-header .container { align-items: center; display: flex; gap: 16px; justify-content: space-between; }
    .logo { color: var(--white); font-family: var(--font-display); font-size: 1.6rem; letter-spacing: 0.08em; line-height: 1; }
    .logo span { color: var(--gold); }
    .hero {
      align-items: center;
      display: flex;
      min-height: 100vh;
      overflow: hidden;
      padding: 120px 0 80px;
      position: relative;
    }
    .hero::before {
      background:
        radial-gradient(ellipse at 75% 40%, rgba(201, 168, 76, 0.1) 0%, transparent 55%),
        radial-gradient(ellipse at 18% 82%, rgba(201, 168, 76, 0.06) 0%, transparent 45%);
      content: '';
      inset: 0;
      pointer-events: none;
      position: absolute;
    }
    .hero::after {
      background: linear-gradient(180deg, rgba(17, 17, 17, 0.9), rgba(17, 17, 17, 0.7));
      clip-path: polygon(16% 0, 100% 0, 100% 100%, 0 100%);
      content: '';
      height: 100%;
      position: absolute;
      right: -10%;
      top: 0;
      width: 52%;
      z-index: 0;
    }
    .hero .container {
      align-items: center;
      display: grid;
      gap: 60px;
      grid-template-columns: 1fr 1fr;
      position: relative;
      z-index: 1;
    }
    .hero-content h1 {
      font-family: var(--font-display);
      font-size: clamp(3rem, 6vw, 5.4rem);
      letter-spacing: 0.03em;
      line-height: 0.98;
      margin: 0 0 20px;
    }
    .hero-content h1 .highlight { color: var(--gold); display: block; }
    .hero-content p { color: #cfcfcf; font-size: 1.08rem; margin-bottom: 34px; max-width: 500px; }
    .hero-content .badge { margin-bottom: 20px; }
    .hero-ctas, .final-cta-buttons { display: flex; flex-wrap: wrap; gap: 16px; }
    .hero-image { position: relative; z-index: 1; }
    .hero-image img, .barber-image img {
      border-radius: 10px;
      box-shadow: 0 40px 80px rgba(0, 0, 0, 0.55);
      height: 100%;
      max-height: 520px;
      object-fit: cover;
      width: 100%;
    }
    .hero-badge-float {
      background: var(--gold);
      border-radius: 8px;
      bottom: -20px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
      color: var(--black);
      font-size: 0.9rem;
      font-weight: 700;
      left: -20px;
      line-height: 1.3;
      padding: 14px 20px;
      position: absolute;
    }
    .hero-badge-float strong {
      display: block;
      font-family: var(--font-display);
      font-size: 1.4rem;
    }
    .media-fallback {
      background:
        linear-gradient(145deg, rgba(201, 168, 76, 0.14), rgba(201, 168, 76, 0.03)),
        linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(0, 0, 0, 0.12));
      border: 1px solid rgba(201, 168, 76, 0.2);
      border-radius: 10px;
      box-shadow: 0 40px 80px rgba(0, 0, 0, 0.42);
      display: flex;
      flex-direction: column;
      gap: 22px;
      justify-content: space-between;
      min-height: 420px;
      overflow: hidden;
      padding: 28px;
      position: relative;
    }
    .media-fallback::before {
      background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.08), transparent 32%);
      content: '';
      inset: 0;
      pointer-events: none;
      position: absolute;
    }
    .media-fallback > * { position: relative; z-index: 1; }
    .media-kicker {
      color: var(--gold);
      display: inline-block;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.14em;
      margin-bottom: 14px;
      text-transform: uppercase;
    }
    .media-fallback h3 {
      font-family: var(--font-display);
      font-size: clamp(2rem, 3vw, 2.8rem);
      letter-spacing: 0.05em;
      line-height: 1;
      margin-bottom: 12px;
    }
    .media-fallback p { color: #d0d0d0; max-width: 420px; }
    .media-points { display: grid; gap: 10px; }
    .media-point {
      align-items: center;
      background: rgba(0, 0, 0, 0.18);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 6px;
      color: #efefef;
      display: flex;
      font-size: 0.96rem;
      font-weight: 600;
      gap: 10px;
      padding: 10px 12px;
    }
    .media-point strong {
      color: var(--gold);
      font-family: var(--font-display);
      font-size: 1.2rem;
      letter-spacing: 0.08em;
      min-width: 50px;
    }
    .brands-strip {
      background: var(--dark);
      border-bottom: 1px solid var(--border);
      border-top: 1px solid var(--border);
      overflow: hidden;
      padding: 24px 0;
    }
    .brands-strip p {
      color: var(--gray);
      font-size: 0.75rem;
      letter-spacing: 0.15em;
      margin-bottom: 16px;
      text-align: center;
      text-transform: uppercase;
    }
    .brands-list {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 28px 40px;
      justify-content: center;
    }
    .brand-name {
      color: var(--gray);
      font-family: var(--font-display);
      font-size: 1.3rem;
      letter-spacing: 0.1em;
      transition: color .2s ease;
    }
    .brand-name:hover { color: var(--gold); }
    .brands-sep { color: var(--border); font-size: 1.2rem; }
    .section { padding: 84px 0; }
    .section-title {
      font-family: var(--font-display);
      font-size: clamp(2rem, 4vw, 3.1rem);
      letter-spacing: 0.05em;
      line-height: 1.08;
      margin-bottom: 16px;
    }
    .section-sub {
      color: var(--gray);
      font-size: 1.05rem;
      line-height: 1.7;
      margin-bottom: 46px;
      max-width: 580px;
    }
    .pain-grid, .products-grid, .numbers-grid, .testimonials-grid, .info-inner, .barber-inner {
      display: grid;
      gap: 22px;
    }
    .pain-grid { grid-template-columns: repeat(2, 1fr); }
    .pain-card, .product-card, .testimonial-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; }
    .pain-card { padding: 28px; transition: border-color .25s ease, transform .25s ease; }
    .pain-card:hover { border-color: var(--gold); transform: translateY(-2px); }
    .pain-card .icon { font-size: 1.8rem; margin-bottom: 12px; }
    .pain-card h3 { font-size: 1.08rem; margin-bottom: 8px; }
    .pain-card p { color: var(--gray); font-size: 0.96rem; }
    .solution-block {
      background: linear-gradient(135deg, rgba(201, 168, 76, 0.14), rgba(201, 168, 76, 0.04));
      border-color: rgba(201, 168, 76, 0.28);
      grid-column: 1 / -1;
    }
    .solution-block h3 { color: var(--gold); font-size: 1.36rem; }
    .products-section, .info-section { background: rgba(17, 17, 17, 0.95); }
    .products-grid { grid-template-columns: repeat(3, 1fr); margin-bottom: 38px; }
    .product-card { overflow: hidden; transition: transform .25s ease, border-color .25s ease; }
    .product-card:hover { border-color: var(--gold); transform: translateY(-4px); }
    .product-card img, .product-placeholder {
      background: linear-gradient(135deg, rgba(201, 168, 76, 0.08), rgba(255, 255, 255, 0.02));
      height: 220px;
      object-fit: cover;
      width: 100%;
    }
    .product-placeholder {
      align-items: center;
      color: #777;
      display: flex;
      font-size: 0.84rem;
      justify-content: center;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .product-card-body { padding: 16px; }
    .product-card-body .cat {
      color: var(--gold);
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.15em;
      margin-bottom: 6px;
      text-transform: uppercase;
    }
    .product-card-body h3 { font-size: 1rem; margin-bottom: 8px; }
    .product-card-body p { color: var(--gray); font-size: 0.88rem; margin-bottom: 12px; min-height: 42px; }
    .product-card-body .price {
      color: var(--white);
      font-family: var(--font-display);
      font-size: 1.32rem;
      margin-bottom: 14px;
    }
    .product-card-body .btn { padding: 12px 14px; width: 100%; }
    .products-cta-bar { text-align: center; }
    .products-fallback { grid-column: 1 / -1; padding: 34px 20px; text-align: center; }
    .products-fallback p { color: var(--gray); margin-bottom: 18px; }
    .barber-section { overflow: hidden; position: relative; }
    .barber-section::before {
      background: radial-gradient(ellipse at 24% 50%, rgba(201, 168, 76, 0.08), transparent 70%);
      content: '';
      inset: 0;
      pointer-events: none;
      position: absolute;
    }
    .barber-inner {
      align-items: center;
      grid-template-columns: 1fr 1fr;
      gap: 60px;
      position: relative;
      z-index: 1;
    }
    .barber-content .badge { margin-bottom: 20px; }
    .barber-content p { color: #cdcdcd; font-size: 1.04rem; line-height: 1.8; margin-bottom: 30px; }
    .barber-items { list-style: none; margin-bottom: 34px; }
    .barber-items li {
      align-items: center;
      border-bottom: 1px solid var(--border);
      display: flex;
      gap: 12px;
      padding: 10px 0;
    }
    .barber-items li:last-child { border-bottom: none; }
    .check { color: var(--gold); flex-shrink: 0; font-size: 1.1rem; }
    .numbers-section { background: var(--gold); padding: 60px 0; }
    .numbers-grid { grid-template-columns: repeat(4, 1fr); text-align: center; }
    .number-item .value {
      color: var(--black);
      display: block;
      font-family: var(--font-display);
      font-size: clamp(2.6rem, 5vw, 4rem);
      line-height: 1;
    }
    .number-item .label {
      color: rgba(0, 0, 0, 0.68);
      display: block;
      font-size: 0.83rem;
      font-weight: 700;
      letter-spacing: 0.1em;
      margin-top: 4px;
      text-transform: uppercase;
    }
    .testimonials-grid { grid-template-columns: repeat(3, 1fr); }
    .testimonial-card { padding: 28px; }
    .testimonial-card .stars { color: var(--gold); font-size: 1rem; margin-bottom: 14px; }
    .testimonial-card p { color: #cfcfcf; font-size: 0.96rem; font-style: italic; margin-bottom: 20px; }
    .testimonial-card .author { font-weight: 700; }
    .testimonial-card .author-sub { color: var(--gray); font-size: 0.82rem; }
    .info-inner {
      align-items: start;
      grid-template-columns: 1fr 1fr;
      gap: 40px;
    }
    .info-block h3 {
      font-family: var(--font-display);
      font-size: 1.64rem;
      letter-spacing: 0.05em;
      margin-bottom: 20px;
    }
    .hours-list { list-style: none; }
    .hours-list li {
      border-bottom: 1px solid var(--border);
      display: flex;
      font-size: 0.96rem;
      justify-content: space-between;
      padding: 10px 0;
    }
    .hours-list li:last-child { border-bottom: none; }
    .hours-list .day { color: var(--gray); }
    .hours-list .time { font-weight: 600; }
    .map-placeholder {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      overflow: hidden;
    }
    .map-placeholder iframe {
      border: none;
      display: block;
      height: 320px;
      width: 100%;
    }
    .map-address { color: var(--gray); font-size: 0.92rem; padding: 16px; }
    .map-address strong { color: var(--white); display: block; margin-bottom: 4px; }
    .map-links {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 16px;
    }
    .map-links .btn { padding: 12px 18px; }
    .location-note { color: #a6a6a6; font-size: 0.78rem; line-height: 1.6; margin-top: 12px; }
    .final-cta {
      overflow: hidden;
      padding: 100px 0;
      position: relative;
      text-align: center;
    }
    .final-cta::before {
      background: radial-gradient(ellipse at center, rgba(201, 168, 76, 0.12), transparent 64%);
      content: '';
      inset: 0;
      pointer-events: none;
      position: absolute;
    }
    .final-cta .container { position: relative; z-index: 1; }
    .final-cta .section-title { font-size: clamp(2.4rem, 5vw, 4.4rem); margin-bottom: 16px; }
    .final-cta p { color: #ccc; font-size: 1.08rem; margin: 0 auto 38px; max-width: 580px; }
    .lp-footer {
      background: var(--dark);
      border-top: 1px solid var(--border);
      padding: 24px 0;
      text-align: center;
    }
    .lp-footer p { color: var(--gray); font-size: 0.85rem; }
    .lp-footer a { color: var(--gold); }
    .wa-float {
      align-items: center;
      background: var(--green-wa);
      border-radius: 50%;
      bottom: 28px;
      box-shadow: 0 4px 20px rgba(37, 211, 102, 0.48);
      color: #fff;
      display: flex;
      height: 60px;
      justify-content: center;
      position: fixed;
      right: 28px;
      transition: transform .25s ease, box-shadow .25s ease;
      width: 60px;
      z-index: 9999;
    }
    .wa-float:hover { box-shadow: 0 8px 30px rgba(37, 211, 102, 0.58); transform: scale(1.08); }
    .wa-float::before {
      animation: wa-pulse 2s infinite;
      background: rgba(37, 211, 102, 0.2);
      border-radius: 50%;
      content: '';
      inset: -6px;
      position: absolute;
    }
    @keyframes wa-pulse {
      0% { opacity: 1; transform: scale(1); }
      70% { opacity: 0; transform: scale(1.4); }
      100% { opacity: 0; transform: scale(1.4); }
    }
    [data-fade] { opacity: 0; transform: translateY(30px); transition: opacity .6s ease, transform .6s ease; }
    [data-fade].visible { opacity: 1; transform: translateY(0); }
    @media (max-width: 900px) {
      .hero::after { display: none; }
      .hero .container, .barber-inner, .info-inner { grid-template-columns: 1fr; }
      .hero-image, .barber-image { order: -1; }
      .pain-grid { grid-template-columns: 1fr; }
      .products-grid { grid-template-columns: repeat(2, 1fr); }
      .numbers-grid { grid-template-columns: repeat(2, 1fr); }
      .testimonials-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .lp-header .container { align-items: flex-start; flex-direction: column; }
      .hero { min-height: auto; padding-top: 150px; }
      .hero-content h1 { font-size: 3rem; }
      .hero-ctas, .final-cta-buttons { flex-direction: column; }
      .products-grid { grid-template-columns: 1fr; }
      .media-fallback, .hero-image img, .barber-image img { min-height: 340px; }
      .hero-badge-float { bottom: -10px; left: 0; }
      .wa-float { bottom: 20px; right: 20px; }
    }
  </style>
</head>
<body>
<?php if ($metaPixelId !== ''): ?>
  <noscript>
    <img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=<?= lp_escape($metaPixelId) ?>&ev=PageView&noscript=1">
  </noscript>
<?php endif; ?>
<?php if ($hasUtm): ?>
  <script>
    sessionStorage.setItem('utm_source', <?= json_encode($utm_source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    sessionStorage.setItem('utm_medium', <?= json_encode($utm_medium, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    sessionStorage.setItem('utm_campaign', <?= json_encode($utm_campaign, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    sessionStorage.setItem('utm_content', <?= json_encode($utm_content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
  </script>
<?php endif; ?>
  <header class="lp-header">
    <div class="container">
      <div class="logo">FOR <span>MEN</span> STORE</div>
      <a href="<?= lp_escape($whatsapp_link) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-track" data-event="header_whatsapp">
        <span>WhatsApp</span>
      </a>
    </div>
  </header>

  <section class="hero">
    <div class="container">
      <div class="hero-content" data-fade>
        <span class="badge">Revendedor oficial - 6 anos de mercado</span>
        <h1>
          Tudo que o
          <span class="highlight">Homem de Estilo</span>
          Precisa
        </h1>
        <p>
          Nike. Adidas. Puma. New Balance. John John. New Era.
          Produtos <strong>100% originais</strong>, atendimento rapido e um unico lugar para montar o look completo.
        </p>
        <div class="hero-ctas">
          <a href="<?= lp_escape($whatsapp_link) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-track" data-event="hero_whatsapp">
            Chamar no WhatsApp
          </a>
          <a href="#localizacao" class="btn btn-outline">
            Ver localizacao
          </a>
        </div>
      </div>

      <div class="hero-image" data-fade>
<?php if ($heroImageUrl !== ''): ?>
        <img src="<?= lp_escape($heroImageUrl) ?>" alt="For Men Store - ambiente da loja" loading="eager">
<?php else: ?>
        <div class="media-fallback">
          <div>
            <span class="media-kicker">Experiencia premium</span>
            <h3>Loja fisica pronta para vestir voce com rapidez.</h3>
            <p>Sem perder tempo rodando a cidade. Aqui voce encontra marcas originais, atendimento consultivo e saida rapida via WhatsApp.</p>
          </div>
          <div class="media-points">
            <div class="media-point"><strong>01</strong> roupa, tenis, bone e acessorios no mesmo endereco</div>
            <div class="media-point"><strong>02</strong> reserve pelo WhatsApp e retire na loja</div>
            <div class="media-point"><strong>03</strong> atendimento pensado para decisao imediata</div>
          </div>
        </div>
<?php endif; ?>
        <div class="hero-badge-float">
          <strong>+6 anos</strong>
          vestindo com estilo
        </div>
      </div>
    </div>
  </section>

  <section class="brands-strip">
    <p>Marcas originais disponiveis</p>
    <div class="container">
      <div class="brands-list">
        <span class="brand-name">NIKE</span>
        <span class="brands-sep">.</span>
        <span class="brand-name">ADIDAS</span>
        <span class="brands-sep">.</span>
        <span class="brand-name">PUMA</span>
        <span class="brands-sep">.</span>
        <span class="brand-name">NEW BALANCE</span>
        <span class="brands-sep">.</span>
        <span class="brand-name">JOHN JOHN</span>
        <span class="brands-sep">.</span>
        <span class="brand-name">NEW ERA</span>
        <span class="brands-sep">.</span>
        <span class="brand-name">AEROPOSTALE</span>
        <span class="brands-sep">.</span>
        <span class="brand-name">BLCK BR</span>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <div class="text-center" data-fade>
        <div class="badge" style="margin-bottom:16px">O problema que a gente resolve</div>
        <h2 class="section-title">Cansado de perder tempo em loja que nao tem o que voce quer?</h2>
        <p class="section-sub" style="margin:0 auto 48px">Se voce quer praticidade, originalidade e atendimento rapido, a For Men foi montada para isso.</p>
      </div>

      <div class="pain-grid">
        <div class="pain-card" data-fade>
          <div class="icon">01</div>
          <h3>Produto com cara de original, mas sem procedencia</h3>
          <p>Voce paga preco de marca e leva risco junto. Aqui o foco e produto original e atendimento objetivo.</p>
        </div>
        <div class="pain-card" data-fade>
          <div class="icon">02</div>
          <h3>Ter que ir em varias lojas para fechar um look</h3>
          <p>Tenis em um lugar, camisa em outro, bone em outro. A proposta da For Men e resolver isso em uma unica visita.</p>
        </div>
        <div class="pain-card" data-fade>
          <div class="icon">03</div>
          <h3>Atendimento que nao entende moda masculina</h3>
          <p>A equipe atende com repertorio de marca, combinacao e acabamento para acelerar sua decisao sem empurrar produto.</p>
        </div>
        <div class="pain-card solution-block" data-fade>
          <div class="icon">04</div>
          <h3>A For Men Store resolve tudo isso</h3>
          <p>Roupa original, tenis, bone, perfume, acessorios e apoio via WhatsApp para reservar, tirar duvidas e trazer voce para a loja no momento certo.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="section products-section">
    <div class="container">
      <div data-fade>
        <div class="badge" style="margin-bottom:16px">Produtos em destaque</div>
        <h2 class="section-title">Selecao da semana</h2>
        <p class="section-sub">Os destaques saem direto do banco, sem depender do endpoint protegido do catalogo.</p>
      </div>

      <div class="products-grid">
<?php if (!empty($produtos_destaque)): ?>
<?php foreach ($produtos_destaque as $produto): ?>
<?php
    $nome = trim((string)($produto['nome'] ?? ''));
    $categoria = ucfirst(strtolower(trim((string)($produto['categoria'] ?? ($produto['tipo'] ?? 'Produto')))));
    $descricao = lp_excerpt((string)($produto['descricao'] ?? ''), 80);
    $preco = 'R$ ' . number_format((float)($produto['preco'] ?? 0), 2, ',', '.');
    $imagem = !empty($produto['imagem']) ? image_url((string)$produto['imagem']) : '';
    $produtoWaLink = lp_whatsapp_link($whatsapp_number, 'Ola! Vi ' . $nome . ' na landing page e quero mais informacoes.');
    $productEvent = 'product_' . lp_slugify($nome);
?>
        <div class="product-card" data-fade>
<?php if ($imagem !== ''): ?>
          <img src="<?= lp_escape($imagem) ?>" alt="<?= lp_escape($nome) ?>" loading="lazy">
<?php else: ?>
          <div class="product-placeholder">Sem imagem</div>
<?php endif; ?>
          <div class="product-card-body">
            <div class="cat"><?= lp_escape($categoria) ?></div>
            <h3><?= lp_escape($nome) ?></h3>
<?php if ($descricao !== ''): ?>
            <p><?= lp_escape($descricao) ?></p>
<?php else: ?>
            <p>Consulte disponibilidade, tamanho e reserva direta no WhatsApp.</p>
<?php endif; ?>
            <div class="price"><?= lp_escape($preco) ?></div>
            <a href="<?= lp_escape($produtoWaLink) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-track" data-event="<?= lp_escape($productEvent) ?>">
              Quero esse
            </a>
          </div>
        </div>
<?php endforeach; ?>
<?php else: ?>
        <div class="products-fallback" data-fade>
          <p>Os produtos em destaque nao puderam ser carregados neste ambiente. O CTA abaixo leva o cliente para o melhor proximo passo.</p>
          <a href="<?= lp_escape($catalogWhatsAppLink) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-track" data-event="products_fallback_whatsapp">
            Ver catalogo no WhatsApp
          </a>
        </div>
<?php endif; ?>
      </div>

      <div class="products-cta-bar" data-fade>
<?php if ($catalogUsesStore): ?>
        <a href="<?= lp_escape($catalogUrl) ?>" class="btn btn-outline btn-track" data-event="ver_catalogo_loja">
          Abrir loja online
        </a>
<?php else: ?>
        <a href="<?= lp_escape($catalogWhatsAppLink) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-track" data-event="ver_catalogo_whatsapp">
          Ver catalogo completo
        </a>
<?php endif; ?>
      </div>
    </div>
  </section>

  <section class="section barber-section">
    <div class="container">
      <div class="barber-inner">
        <div class="barber-image" data-fade>
<?php if ($barberImageUrl !== ''): ?>
          <img src="<?= lp_escape($barberImageUrl) ?>" alt="Barbearia For Men Store" loading="lazy">
<?php else: ?>
          <div class="media-fallback">
            <div>
              <span class="media-kicker">Experiencia completa</span>
              <h3>Veio pelo look. Saiu ainda mais alinhado.</h3>
              <p>Se as fotos reais ainda nao estiverem no projeto, a estrutura continua pronta para publicar assim que `assets/images/` for populada.</p>
            </div>
            <div class="media-points">
              <div class="media-point"><strong>BAR</strong> barbearia integrada ao fluxo da loja</div>
              <div class="media-point"><strong>CTA</strong> agendamento imediato via WhatsApp</div>
              <div class="media-point"><strong>VIP</strong> ambiente masculino e premium</div>
            </div>
          </div>
<?php endif; ?>
        </div>

        <div class="barber-content" data-fade>
          <span class="badge">Exclusivo</span>
          <h2 class="section-title">
            Veio buscar o look.
            <span class="text-gold">Aproveitou e fez o cabelo.</span>
          </h2>
          <p>
            A proposta e simples: reduzir friccao. O cliente resolve vestuario, acessorios e atendimento rapido no mesmo fluxo. Se quiser, ainda agenda a barbearia sem sair da conversa.
          </p>
          <ul class="barber-items">
            <li><span class="check">+</span> Barbearia integrada a experiencia da loja</li>
            <li><span class="check">+</span> Reserva e atendimento direto pelo WhatsApp</li>
            <li><span class="check">+</span> Ambiente masculino, objetivo e premium</li>
            <li><span class="check">+</span> Perfeito para trafego pago com decisao rapida</li>
          </ul>
          <a href="<?= lp_escape($barberWhatsAppLink) ?>" target="_blank" rel="noopener" class="btn btn-gold btn-track" data-event="barbearia_cta">
            Agendar na barbearia
          </a>
        </div>
      </div>
    </div>
  </section>

  <section class="numbers-section">
    <div class="container">
      <div class="numbers-grid">
        <div class="number-item" data-fade>
          <span class="value">+6</span>
          <span class="label">Anos no mercado</span>
        </div>
        <div class="number-item" data-fade>
          <span class="value">8+</span>
          <span class="label">Marcas originais</span>
        </div>
        <div class="number-item" data-fade>
          <span class="value">1</span>
          <span class="label">Endereco para resolver tudo</span>
        </div>
        <div class="number-item" data-fade>
          <span class="value">100%</span>
          <span class="label">Produtos originais</span>
        </div>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <div class="text-center" data-fade style="margin-bottom:48px">
        <div class="badge" style="margin-bottom:16px">O que os clientes dizem</div>
        <h2 class="section-title">Quem comprou, aprovou.</h2>
      </div>

      <div class="testimonials-grid">
        <div class="testimonial-card" data-fade>
          <div class="stars">★★★★★</div>
          <p>"Fui ver um tenis, fechei o look completo e ainda consegui resolver tudo rapido pelo WhatsApp antes de sair de casa."</p>
          <div class="author">Lucas M.</div>
          <div class="author-sub">Cliente recorrente</div>
        </div>
        <div class="testimonial-card" data-fade>
          <div class="stars">★★★★★</div>
          <p>"Produto original, atendimento sem enrolacao e opcoes que realmente combinam. Loja pensada para homem que quer praticidade."</p>
          <div class="author">Rafael A.</div>
          <div class="author-sub">Comprou e retirou na loja</div>
        </div>
        <div class="testimonial-card" data-fade>
          <div class="stars">★★★★★</div>
          <p>"Mandei mensagem, reservaram a peca e fui buscar no mesmo dia. E exatamente o tipo de experiencia que converte."</p>
          <div class="author">Diego S.</div>
          <div class="author-sub">Atendido via WhatsApp</div>
        </div>
      </div>
    </div>
  </section>

  <section class="section info-section" id="localizacao">
    <div class="container">
      <div class="info-inner">
        <div class="info-block" data-fade>
          <h3>Horarios de atendimento</h3>
          <ul class="hours-list">
            <li><span class="day">Segunda a sexta</span><span class="time">8h30 - 19h00</span></li>
            <li><span class="day">Sabado</span><span class="time">8h30 - 19h00</span></li>
            <li><span class="day">Domingo</span><span class="time">9h00 - 12h00</span></li>
          </ul>
          <div style="margin-top:28px">
            <a href="<?= lp_escape($whatsapp_link) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-track" data-event="info_whatsapp">
              Falar agora no WhatsApp
            </a>
          </div>
        </div>

        <div class="info-block" data-fade>
          <h3>Nossa localizacao</h3>
          <div class="map-placeholder">
            <iframe
              src="<?= lp_escape($mapEmbedUrl) ?>"
              allowfullscreen
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"></iframe>
            <div class="map-address">
              <strong><?= lp_escape($companyName) ?> - <?= lp_escape($companyLegalName) ?></strong>
              <?= lp_escape($storeAddress) ?>
              <div class="map-links">
                <a href="<?= lp_escape($mapOpenUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-track" data-event="map_open">
                  Abrir no Maps
                </a>
                <a href="<?= lp_escape($whatsapp_link) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-track" data-event="map_whatsapp">
                  Reservar pelo WhatsApp
                </a>
              </div>
              <p class="location-note">
                Endereco preenchido com fallback configuravel por ambiente. Se houver endereco oficial diferente, substitua por `LANDING_STORE_ADDRESS` ou `LANDING_MAP_EMBED_URL`.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="final-cta">
    <div class="container">
      <div data-fade>
        <h2 class="section-title">Nao deixe o look <span class="text-gold">para depois.</span></h2>
        <p>Chame no WhatsApp agora ou venha direto para a loja. A melhor versao do seu estilo comeca em um atendimento que reduz atrito e acelera decisao.</p>
        <div class="final-cta-buttons">
          <a href="<?= lp_escape($whatsapp_link) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-track" data-event="final_whatsapp">
            Chamar no WhatsApp agora
          </a>
          <a href="#localizacao" class="btn btn-outline">
            Como chegar
          </a>
        </div>
      </div>
    </div>
  </section>

  <footer class="lp-footer">
    <div class="container">
      <p>
        &copy; <?= date('Y') ?> <?= lp_escape($companyName) ?> - <?= lp_escape($companyLegalName) ?> |
        <a href="<?= lp_escape($instagramUrl) ?>" target="_blank" rel="noopener">@<?= lp_escape($instagramUser) ?></a>
      </p>
    </div>
  </footer>

  <a href="<?= lp_escape($whatsapp_link) ?>" target="_blank" rel="noopener" class="wa-float btn-track" data-event="float_whatsapp" title="Falar no WhatsApp">
    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="white" aria-hidden="true">
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
      <path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.116 1.527 5.847L.057 23.882l6.196-1.448A11.935 11.935 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.798 9.798 0 0 1-5.001-1.373l-.36-.214-3.678.859.875-3.579-.234-.37A9.792 9.792 0 0 1 2.182 12C2.182 6.573 6.573 2.182 12 2.182S21.818 6.573 21.818 12 17.427 21.818 12 21.818z"/>
    </svg>
  </a>

  <script>
    (function () {
      var elements = document.querySelectorAll('[data-fade]');

      if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              entry.target.classList.add('visible');
              observer.unobserve(entry.target);
            }
          });
        }, { threshold: 0.15 });

        elements.forEach(function (element) {
          observer.observe(element);
        });
      } else {
        elements.forEach(function (element) {
          element.classList.add('visible');
        });
      }

      document.querySelectorAll('.btn-track').forEach(function (button) {
        button.addEventListener('click', function () {
          var eventName = this.dataset.event || 'cta_click';
          var payload = {
            company_id: <?= (int)$company_id ?>,
            event: eventName,
            page: 'landing',
            utm_source: sessionStorage.getItem('utm_source') || <?= json_encode($utm_source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            utm_medium: sessionStorage.getItem('utm_medium') || <?= json_encode($utm_medium, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            utm_campaign: sessionStorage.getItem('utm_campaign') || <?= json_encode($utm_campaign, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            utm_content: sessionStorage.getItem('utm_content') || <?= json_encode($utm_content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
          };

          fetch(<?= json_encode($trackCtaUrl, JSON_UNESCAPED_SLASHES) ?>, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true
          }).catch(function () {});

          if (typeof fbq === 'function') {
            fbq('track', 'Contact', { content_name: eventName });
          }

          if (typeof gtag === 'function') {
            gtag('event', 'cta_click', { event_label: eventName });
          }
        });
      });

      document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
        anchor.addEventListener('click', function (event) {
          var target = document.querySelector(this.getAttribute('href'));
          if (!target) {
            return;
          }

          event.preventDefault();
          var offset = target.getBoundingClientRect().top + window.pageYOffset - 80;
          window.scrollTo({ top: offset, behavior: 'smooth' });
        });
      });
    })();
  </script>
</body>
</html>
