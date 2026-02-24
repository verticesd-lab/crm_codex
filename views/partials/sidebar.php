<?php
// views/partials/sidebar.php

$theme       = current_theme();
$base        = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$currentBase = basename($_SERVER['SCRIPT_NAME'] ?? '');

/* ── Todos os módulos do sistema ────────────────────────────────
   [ key, label, href, requer_admin ]
   ─────────────────────────────────────────────────────────────── */
$ALL_MENU = [
    ['dashboard',            'Dashboard',            $base . '/index.php',               false],
    ['caixa',                'Caixa',                $base . '/pos.php',                 false],
    ['clientes',             'Clientes',             $base . '/clients.php',             false],
    ['funil',                'Funil / Oportunidades',$base . '/opportunities.php',       false],
    ['atendimento',          'Atendimento',          $base . '/atendimento.php',         false],
    ['produtos',             'Produtos/Serviços',    $base . '/products.php',            false],
    ['cadastro_inteligente', 'Cadastro Inteligente', $base . '/products_imports.php',    false],
    ['pedidos',              'Pedidos',              $base . '/orders.php',              false],
    ['promocoes',            'Promoções',            $base . '/promotions.php',          false],
    ['kpis',                 'KPIs',                 $base . '/kpis.php',                false],
    ['analytics',            'Analytics',            $base . '/analytics.php',           false],
    ['canais',               'Canais',               $base . '/integrations.php',        false],
    ['insights_ia',          'Insights IA',          $base . '/insights.php',            false],
    ['agenda',               'Agenda',               $base . '/calendar.php',            false],
    ['agenda_barbearia',     'Agenda Barbearia',     $base . '/calendar_barbearia.php',  false],
    ['servicos_barbearia',   'Serviços Barbearia',   $base . '/services_admin.php',      false],
    ['equipe',               'Equipe',               $base . '/staff.php',               false],
    ['configuracoes',        'Configurações',        $base . '/settings.php',            false], // ← SEMPRE visível para quem tem acesso admin
];

/* ── Carrega módulos ativos (sessão → banco) ─────────────────── */
$activeModules = null;

if (isset($_SESSION['modules_config']) && is_array($_SESSION['modules_config'])) {
    $activeModules = $_SESSION['modules_config'];
} else {
    try {
        $cid = current_company_id();
        if ($cid) {
            $_sb_pdo = get_pdo();
            $_sb_row = $_sb_pdo->prepare('SELECT modules_config FROM companies WHERE id = ? LIMIT 1');
            $_sb_row->execute([$cid]);
            $_sb_val = $_sb_row->fetchColumn();
            if ($_sb_val) {
                $decoded = json_decode($_sb_val, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $activeModules = $decoded;
                    $_SESSION['modules_config'] = $decoded;
                }
            }
        }
    } catch (Throwable $e) { /* silencia */ }
}

// Nunca foi configurado → mostra tudo
$showAll = ($activeModules === null || empty($activeModules));

/* ── Filtra links visíveis ──────────────────────────────────────
   SEM filtro por is_admin aqui — quem chegou até o sidebar
   já passou pelo require_login(). A restrição de página
   específica fica no próprio arquivo (ex: require_admin()).
   O módulo "Configurações" é SEMPRE exibido (chave forçada abaixo).
   ─────────────────────────────────────────────────────────────── */
$visibleLinks = [];
foreach ($ALL_MENU as [$key, $label, $href, $requiresAdmin]) {
    // Configurações sempre aparece — independente do JSON
    if ($key === 'configuracoes') {
        $visibleLinks[$label] = $href;
        continue;
    }
    // Verifica se módulo está ativo
    if (!$showAll && empty($activeModules[$key])) continue;
    $visibleLinks[$label] = $href;
}
?>

<aside class="w-64 min-h-screen sticky top-0 <?= $theme === 'dark'
    ? 'bg-slate-900 text-white border-r border-slate-800'
    : 'bg-slate-900 text-white border-r border-slate-800' ?>">

    <div class="p-4 border-b border-slate-800">
        <p class="text-xs uppercase tracking-wide text-slate-400">Empresa</p>
        <p class="font-semibold text-lg"><?= sanitize($_SESSION['company_name'] ?? 'Minha Empresa') ?></p>
    </div>

    <nav class="p-4 space-y-2">
        <?php foreach ($visibleLinks as $label => $href): ?>
            <?php
                $hrefBase = basename(parse_url($href, PHP_URL_PATH) ?? '');
                $active   = ($hrefBase !== '' && $hrefBase === $currentBase);
            ?>
            <a href="<?= sanitize($href) ?>"
               class="block px-3 py-2 rounded transition <?= $active ? 'bg-indigo-600' : 'hover:bg-slate-800' ?>">
                <?= sanitize($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>