<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (is_logged_in()) {
    header('Location: /index.php');
    exit;
}

$pdo = get_pdo();
$brandStmt = $pdo->query('SELECT id, nome_fantasia, logo, favicon FROM companies ORDER BY id ASC LIMIT 1');
$brand = $brandStmt->fetch() ?: [];
$GLOBALS['__GUEST_COMPANY__'] = $brand;
$GLOBALS['__LOGIN_BG__'] = $brand['logo'] ?? '';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    if (!$email || !$senha) {
        $error = 'Informe e-mail e senha.';
    } else {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('SELECT u.*, c.slug, c.nome_fantasia, c.logo, c.favicon FROM users u JOIN companies c ON c.id = u.company_id WHERE u.email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($senha, $user['senha'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['company_id'] = $user['company_id'];
            $_SESSION['nome'] = $user['nome'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['company_slug'] = $user['slug'];
            $_SESSION['company_name'] = $user['nome_fantasia'];
            $_SESSION['company_logo'] = $user['logo'];
            $_SESSION['company_favicon'] = $user['favicon'];
            header('Location: /index.php');
            exit;
        } else {
            $error = 'Credenciais inválidas.';
        }
    }
}
include __DIR__ . '/views/partials/header.php';
?>
<div class="max-w-xl w-full bg-white/95 border border-slate-200 rounded-2xl shadow-2xl">
    <div class="p-10">
        <div class="w-full flex flex-col items-center mb-4">
            <?php if (!empty($brand['logo'])): ?>
                <img src="<?= sanitize($brand['logo']) ?>" alt="Logo" class="h-36 w-36 object-contain mb-6 drop-shadow-xl">
            <?php else: ?>
                <div class="h-20 w-20 rounded-full bg-indigo-600 text-white flex items-center justify-center text-2xl font-semibold mb-3"><?= strtoupper(substr($brand['nome_fantasia'] ?? APP_NAME, 0, 2)) ?></div>
            <?php endif; ?>
        </div>
        <h1 class="text-3xl font-semibold mb-3 text-center">Entrar</h1>
        <p class="text-sm text-slate-500 mb-6 text-center">Acesse o painel da sua empresa.</p>
        <?php if ($error): ?>
            <div class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200"><?= sanitize($error) ?></div>
        <?php endif; ?>
        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-sm text-slate-600 mb-1">E-mail</label>
                <input name="email" type="email" class="w-full h-12 rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500 text-base px-3" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Senha</label>
                <input name="senha" type="password" class="w-full h-12 rounded-lg border-slate-300 focus:border-indigo-500 focus:ring-indigo-500 text-base px-3" required>
            </div>
            <button type="submit" class="w-full bg-indigo-600 text-white py-3 rounded-lg hover:bg-indigo-700 text-base font-semibold shadow-sm">Entrar</button>
        </form>
    </div>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>





