<?php
// views/partials/sidebar.php

$theme = current_theme();

// BASE_URL pode vir do config.php; deixa fallback seguro
$base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';

// Detecta a página atual (ex: /products.php)
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
$currentBase   = basename($currentScript);

// Links do menu
$links = [
    'Dashboard'              => $base . '/index.php',
    'PDV'                    => $base . '/pos.php',
    'Clientes'               => $base . '/clients.php',
    'Funil / Oportunidades'  => $base . '/opportunities.php',
    'Produtos/Serviços'      => $base . '/products.php',

    // ✅ Importador
    'Cadastro Inteligente'   => $base . '/products_imports.php',

    'Pedidos'                => $base . '/orders.php',
    'Promoções'              => $base . '/promotions.php',
    'KPIs'                   => $base . '/analytics.php',
    'Canais'                 => $base . '/integrations.php',
    'Insights IA'            => $base . '/insights.php',
    'Agenda'                 => $base . '/calendar.php',

    // ✅ Barbearia
    'Agenda Barbearia'       => $base . '/calendar_barbearia.php',
    'Serviços Barbearia'     => $base . '/services_admin.php',   // <-- AQUI (logo abaixo)

    'Equipe'                 => $base . '/staff.php',
    'Configurações'          => $base . '/settings.php',
];

?>
<aside class="w-64 min-h-screen sticky top-0
    <?= $theme === 'dark'
        ? 'bg-slate-900 text-white border-r border-slate-800'
        : 'bg-slate-900 text-white border-r border-slate-800'
    ?>">
    <div class="p-4 border-b border-slate-800">
        <p class="text-xs uppercase tracking-wide text-slate-400">Empresa</p>
        <p class="font-semibold text-lg"><?= sanitize($_SESSION['company_name'] ?? 'Minha Empresa') ?></p>
    </div>

    <nav class="p-4 space-y-2">
        <?php foreach ($links as $label => $href): ?>
            <?php
                $hrefBase = basename(parse_url($href, PHP_URL_PATH) ?? '');
                $active = ($hrefBase !== '' && $hrefBase === $currentBase);
            ?>
            <a href="<?= $href ?>"
               class="block px-3 py-2 rounded transition
               <?= $active ? 'bg-indigo-600' : 'hover:bg-slate-800' ?>">
                <?= sanitize($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
