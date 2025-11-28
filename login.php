<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Se já estiver logado, manda pro dashboard
if (is_logged_in()) {
    redirect('index.php');
}

$pdo = get_pdo();

$flashError = get_flash('error') ?? '';

// tenta buscar uma empresa para usar o logo como fundo
$companyBg = null;
try {
    $stmtBg = $pdo->query('SELECT * FROM companies ORDER BY id LIMIT 1');
    $companyBg = $stmtBg->fetch();
} catch (Throwable $e) {
    $companyBg = null;
}

// trata login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if ($email === '' || $senha === '') {
        $flashError = 'Informe e-mail e senha.';
    } else {
        $stmt = $pdo->prepare('
            SELECT u.*, c.id AS company_id, c.nome_fantasia, c.slug, c.logo, c.favicon
            FROM users u
            INNER JOIN companies c ON c.id = u.company_id
            WHERE u.email = ?
            LIMIT 1
        ');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha'])) {
            $flashError = 'Credenciais inválidas.';
        } else {
            // Seta sessão
            $_SESSION['user_id']      = (int)$user['id'];
            $_SESSION['company_id']   = (int)$user['company_id'];
            $_SESSION['company_name'] = $user['nome_fantasia'] ?? 'Empresa';
            $_SESSION['company_slug'] = $user['slug'] ?? '';
            $_SESSION['company_logo'] = $user['logo'] ?? '';
            $_SESSION['role']         = $user['role'] ?? 'user';
            $_SESSION['nome']         = $user['nome'] ?? '';

            redirect('index.php');
        }
    }

    if ($flashError) {
        flash('error', $flashError);
        redirect('login.php');
    }
}

$flashError = get_flash('error') ?? $flashError;

// monta estilo de fundo com logo da empresa
$bgStyle = '';
if (!empty($companyBg['logo'])) {
    $bg = $companyBg['logo'];
    if (!str_starts_with($bg, 'http://') && !str_starts_with($bg, 'https://')) {
        $bg = BASE_URL . '/' . ltrim($bg, '/');
    }
    $bgStyle = "background-image:url('".htmlspecialchars($bg, ENT_QUOTES, 'UTF-8')."');background-size:cover;background-position:center;background-repeat:no-repeat;";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Login - Micro CRM SaaS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    colors: {
                        brand: {
                            500: '#4f46e5',
                            600: '#4338ca',
                            700: '#3730a3',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <div class="min-h-screen w-full flex items-center justify-center p-6 relative overflow-hidden"
         style="<?= $bgStyle ?>">
        <!-- overlay escuro em cima do fundo -->
        <div class="absolute inset-0 bg-slate-900/75 backdrop-blur-sm"></div>

        <div class="relative z-10 w-full max-w-md">
            <div class="mb-6 text-center">
                <?php if (!empty($companyBg['logo'])): ?>
                    <?php
                    $logo = $companyBg['logo'];
                    if (!str_starts_with($logo, 'http://') && !str_starts_with($logo, 'https://')) {
                        $logo = BASE_URL . '/' . ltrim($logo, '/');
                    }
                    ?>
                    <img src="<?= sanitize($logo) ?>" class="mx-auto h-16 w-16 rounded-full border border-white/40 object-cover mb-3">
                <?php endif; ?>
                <p class="text-xs uppercase tracking-[0.25em] text-slate-300">Acesso ao painel</p>
                <h2 class="mt-1 text-2xl font-semibold">
                    <?= sanitize($companyBg['nome_fantasia'] ?? 'Micro CRM SaaS') ?>
                </h2>
            </div>

            <?php if ($flashError): ?>
                <div class="mb-4 p-3 rounded-lg bg-red-500/10 text-red-300 border border-red-500/40 text-sm">
                    <?= sanitize($flashError) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4 bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl shadow-black/60">
                <div>
                    <label class="text-sm text-slate-200">E-mail</label>
                    <input
                        type="email"
                        name="email"
                        required
                        class="mt-1 w-full rounded-lg bg-slate-950/80 border border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                        placeholder="voce@empresa.com"
                    >
                </div>
                <div>
                    <label class="text-sm text-slate-200">Senha</label>
                    <input
                        type="password"
                        name="senha"
                        required
                        class="mt-1 w-full rounded-lg bg-slate-950/80 border border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500"
                        placeholder="********"
                    >
                </div>
                <button
                    class="w-full mt-2 bg-brand-600 hover:bg-brand-700 transition-colors text-white font-semibold py-2.5 rounded-lg text-sm">
                    Entrar no painel
                </button>
            </form>
        </div>
    </div>
</body>
</html>
