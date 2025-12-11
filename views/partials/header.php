<?php
require_once __DIR__ . '/../../helpers.php';
$theme = current_theme();
$guestCompany = $GLOBALS['__GUEST_COMPANY__'] ?? null;

$companyName = $_SESSION['company_name'] ?? ($guestCompany['nome_fantasia'] ?? APP_NAME);

// Tenta pegar favicon vindo da empresa / sessão
$favicon = $_SESSION['company_favicon'] ?? ($guestCompany['favicon'] ?? ($guestCompany['logo'] ?? ''));

// SE ainda estiver vazio, usa o logo atual da empresa como fallback
if (empty($favicon)) {
    $logo = current_company_logo();
    if (!empty($logo)) {
        $favicon = $logo;
    }
}

// (Opcional) SE ainda assim não tiver nada, poderia apontar para um favicon padrão na pasta assets
// if (empty($favicon)) {
//     $favicon = BASE_URL . '/assets/favicon.png';
// }
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $theme === 'dark' ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($companyName) ?></title>
    <?php if (!empty($favicon)): ?>
        <link rel="icon" href="<?= sanitize($favicon) ?>" type="image/png">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                }
            }
        };
    </script>
    <!-- JS sempre dentro do /crm_codex -->
    <script src="<?= BASE_URL ?>/assets/js/app.js" defer></script>
</head>
<body class="<?= $theme === 'dark' ? 'bg-slate-900 text-slate-100' : 'bg-slate-50 text-slate-900' ?>">
<div class="min-h-screen flex">
<?php if (is_logged_in()): ?>
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="flex-1 flex flex-col">
        <header class="<?= $theme === 'dark' ? 'bg-slate-900 border-slate-800' : 'bg-white border-slate-200' ?> border-b px-6 py-4 flex items-center justify-between sticky top-0 z-10">
            <div class="flex items-center gap-3">
                <?php if ($logo = current_company_logo()): ?>
                    <img src="<?= sanitize($logo) ?>" class="h-10 w-10 rounded-full border <?= $theme === 'dark' ? 'border-slate-700' : 'border-slate-200' ?> object-cover">
                <?php else: ?>
                    <div class="h-10 w-10 rounded-full bg-indigo-600 text-white flex items-center justify-center font-semibold">
                        <?= strtoupper(substr($_SESSION['company_name'] ?? 'A',0,2)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="text-sm <?= $theme === 'dark' ? 'text-slate-400' : 'text-slate-500' ?>">
                        <?= sanitize($_SESSION['company_name'] ?? 'Empresa') ?>
                    </p>
                    <h1 class="text-xl font-semibold"><?= sanitize(current_user_name()) ?></h1>
                </div>
            </div>
            <div class="flex items-center gap-3 relative">
                <button data-bell class="relative h-10 w-10 rounded-full <?= $theme === 'dark' ? 'bg-slate-800 text-slate-200' : 'bg-slate-100 text-slate-700' ?> flex items-center justify-center hover:ring-2 hover:ring-indigo-500/50">
                    <span aria-hidden="true">🔔</span>
                    <span class="absolute -top-1 -right-1 h-3 w-3 rounded-full bg-emerald-500"></span>
                </button>
                <div data-bell-dropdown class="hidden absolute right-0 top-12 w-64 rounded-lg border <?= $theme === 'dark' ? 'border-slate-700 bg-slate-900' : 'border-slate-200 bg-white' ?> shadow-lg p-3 text-sm">
                    <p class="font-semibold mb-2">Notificações</p>
                    <p class="<?= $theme === 'dark' ? 'text-slate-400' : 'text-slate-600' ?>">Sem notificações no momento.</p>
                </div>

                <!-- Ver loja pública usando BASE_URL -->
                <a class="text-sm text-indigo-600 hover:underline"
                   href="<?= BASE_URL ?>/loja.php?empresa=<?= sanitize($_SESSION['company_slug'] ?? '') ?>"
                   target="_blank">
                    Ver loja pública
                </a>

                <form action="<?= BASE_URL ?>/toggle-theme.php" method="POST" class="inline">
                    <button type="submit" class="px-3 py-2 rounded-full text-sm <?= $theme === 'dark' ? 'bg-slate-800 text-slate-200' : 'bg-slate-100 text-slate-700' ?> hover:ring-2 hover:ring-indigo-500/40">
                        <?= $theme === 'dark' ? 'Modo claro' : 'Modo escuro' ?>
                    </button>
                </form>

                <!-- LOGOUT usando BASE_URL -->
                <a class="text-sm <?= $theme === 'dark' ? 'text-slate-200' : 'text-slate-600' ?> hover:underline"
                   href="<?= BASE_URL ?>/logout.php">
                    Sair
                </a>
            </div>
        </header>
        <main class="p-6 flex-1">
<?php else: ?>
    <?php $loginBg = $GLOBALS['__LOGIN_BG__'] ?? ''; ?>
    <main class="min-h-screen w-full flex items-center justify-center p-6 relative overflow-hidden"
          style="<?= $loginBg ? 'background-image:url(' . sanitize($loginBg) . ');background-size:cover;background-position:center;background-repeat:no-repeat;' : '' ?>">
        <?php if ($loginBg): ?>
            <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
        <?php endif; ?>
        <div class="relative z-10 w-full flex flex-col items-center">
<?php endif; ?>
