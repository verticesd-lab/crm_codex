<?php
$theme = current_theme();
?>
<aside class="w-64 <?= $theme === 'dark' ? 'bg-slate-900 text-white border-r border-slate-800' : 'bg-slate-900 text-white' ?> min-h-screen sticky top-0">
    <div class="p-4 border-b <?= $theme === 'dark' ? 'border-slate-800' : 'border-slate-800' ?>">
        <p class="text-xs uppercase tracking-wide text-slate-400">Empresa</p>
        <p class="font-semibold text-lg"><?= sanitize($_SESSION['company_name'] ?? 'Minha Empresa') ?></p>
    </div>

    <nav class="p-4 space-y-2">
        <?php
        // Agora com BASE_URL
        $links = [
            'Dashboard'            => BASE_URL . '/index.php',
            'PDV'                  => BASE_URL . '/pos.php',
            'Clientes'             => BASE_URL . '/clients.php',
            'Funil / Oportunidades'=> BASE_URL . '/opportunities.php',
            'Produtos/Serviços'    => BASE_URL . '/products.php',
            'Pedidos'              => BASE_URL . '/orders.php',
            'Promoções'            => BASE_URL . '/promotions.php',
            'KPIs'                 => BASE_URL . '/analytics.php',
            'Canais'               => BASE_URL . '/integrations.php',
            'Insights IA'          => BASE_URL . '/insights.php',
            'Agenda'               => BASE_URL . '/calendar.php',
            // 🔹 NOVO: Agenda específica da barbearia (lendo appointments)
            'Agenda Barbearia'     => BASE_URL . '/calendar_barbearia.php',
            'Equipe'               => BASE_URL . '/staff.php',
            'Configurações'        => BASE_URL . '/settings.php',
        ];

        $current = $_SERVER['SCRIPT_NAME'] ?? '';
        foreach ($links as $label => $href):
            $active = str_contains($current, basename($href));
        ?>
            <a href="<?= $href ?>" class="block px-3 py-2 rounded <?= $active ? 'bg-indigo-600' : 'hover:bg-slate-800' ?>">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
