<?php
// views/partials/sidebar.php

$theme       = current_theme();
$base        = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$currentBase = basename($_SERVER['SCRIPT_NAME'] ?? '');

/* ── Todos os módulos do sistema ────────────────────────────────
   [ key, label, href, requer_admin ]
   key deve bater exatamente com o que settings.php salva no JSON
   ─────────────────────────────────────────────────────────────── */
$ALL_MENU = [
    ['dashboard',            'Dashboard',            $base . '/index.php',               false],
    ['caixa',                'Caixa',                $base . '/pos.php',                 false],
    ['clientes',             'Clientes',             $base . '/clients.php',             false],
    ['funil',                'Funil / Oportunidades',$base . '/opportunities.php',       false],
    ['atendimento',          'Atendimento',          $base . '/atendimento.php',         false],
    ['produtos',             'Produtos/Serviços',    $base . '/products.php',            false],
    ['cadastro_inteligente', 'Cadastro Inteligente', $base . '/products_imports.php',    true ],
    ['pedidos',              'Pedidos',              $base . '/orders.php',              false],
    ['promocoes',            'Promoções',            $base . '/promotions.php',          true ],
    ['kpis',                 'KPIs',                 $base . '/kpis.php',                true ],
    ['analytics',            'Analytics',            $base . '/analytics.php',           true ],
    ['canais',               'Canais',               $base . '/integrations.php',        true ],
    ['insights_ia',          'Insights IA',          $base . '/insights.php',            true ],
    ['agenda',               'Agenda',               $base . '/calendar.php',            false],
    ['agenda_barbearia',     'Agenda Barbearia',     $base . '/calendar_barbearia.php',  false],
    ['servicos_barbearia',   'Serviços Barbearia',   $base . '/services_admin.php',      false],
    ['equipe',               'Equipe',               $base . '/staff.php',               true ],
    ['configuracoes',        'Configurações',        $base . '/settings.php',            true ],
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
                if (is_array($decoded)) {
                    $activeModules = $decoded;
                    $_SESSION['modules_config'] = $decoded; // cache na sessão
                }
            }
        }
    } catch (Throwable $e) { /* silencia — não quebra a página */ }
}

// Nunca configurado → mostra tudo (retrocompatível com instalações antigas)
$showAll = ($activeModules === null || empty($activeModules));
$isAdmin = !empty($_SESSION['is_admin']);

/* ── Filtra links visíveis ──────────────────────────────────────
   Regra 1: módulo deve estar ativo no JSON (ou showAll=true)
   Regra 2: se requer_admin=true, só admin enxerga
   ─────────────────────────────────────────────────────────────── */
$visibleLinks = [];
foreach ($ALL_MENU as [$key, $label, $href, $requiresAdmin]) {
    if (!$showAll && empty($activeModules[$key])) continue;
    if ($requiresAdmin && !$isAdmin) continue;
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