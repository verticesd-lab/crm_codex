<?php $theme = current_theme(); ?>
<aside class="w-64 <?= $theme === 'dark' ? 'bg-slate-900 text-white border-r border-slate-800' : 'bg-slate-900 text-white' ?> min-h-screen sticky top-0">
    <div class="p-4 border-b <?= $theme === 'dark' ? 'border-slate-800' : 'border-slate-800' ?>">
        <p class="text-xs uppercase tracking-wide text-slate-400">Empresa</p>
        <p class="font-semibold text-lg"><?= sanitize($_SESSION['company_name'] ?? 'Minha Empresa') ?></p>
    </div>
    <nav class="p-4 space-y-2">
        <?php
        $links = [
            'Dashboard' => '/index.php',
            'PDV' => '/pos.php',
            'Clientes' => '/clients.php',
            'Funil / Oportunidades' => '/opportunities.php',
            'Produtos/Serviços' => '/products.php',
            'Pedidos' => '/orders.php',
            'Promoções' => '/promotions.php',
            'KPIs' => '/analytics.php',
            'Canais' => '/integrations.php',
            'Insights IA' => '/insights.php',
            'Agenda' => '/calendar.php',
            'Equipe' => '/staff.php',
            'Configurações' => '/settings.php',
        ];
        $current = $_SERVER['SCRIPT_NAME'] ?? '';
        foreach ($links as $label => $href):
            $active = str_contains($current, basename($href));
        ?>
            <a href="<?= $href ?>" class="block px-3 py-2 rounded <?= $active ? 'bg-indigo-600' : 'hover:bg-slate-800' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
