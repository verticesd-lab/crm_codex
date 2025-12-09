<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();
require_admin();

$pdo = get_pdo();
$companyId = current_company_id();
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$flashError = $flashSuccess = null;

// Helpers
function load_user(PDO $pdo, int $companyId, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=? AND company_id=?');
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch() ?: null;
}

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $role = trim($_POST['role'] ?? '');

        if ($nome === '' || $email === '' || $senha === '' || !in_array($role, ['admin', 'user'], true)) {
            $flashError = 'Preencha nome, e-mail, senha e papel.';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (company_id, nome, email, senha, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$companyId, $nome, $email, $hash, $role]);
            flash('success', 'Usuário criado com sucesso.');
            redirect(BASE_URL . '/staff.php');
        }
    }

    if ($action === 'edit' && $id) {
        $user = load_user($pdo, $companyId, $id);
        if (!$user) {
            $flashError = 'Usuário não encontrado.';
        } else {
            $nome = trim($_POST['nome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? '');
            $senha = $_POST['senha'] ?? '';
            if ($nome === '' || $email === '' || !in_array($role, ['admin', 'user'], true)) {
                $flashError = 'Preencha nome, e-mail e papel.';
            } else {
                if ($senha !== '') {
                    $hash = password_hash($senha, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('UPDATE users SET nome=?, email=?, role=?, senha=?, updated_at=NOW() WHERE id=? AND company_id=?');
                    $stmt->execute([$nome, $email, $role, $hash, $id, $companyId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET nome=?, email=?, role=?, updated_at=NOW() WHERE id=? AND company_id=?');
                    $stmt->execute([$nome, $email, $role, $id, $companyId]);
                }
                flash('success', 'Usuário atualizado com sucesso.');
                redirect(BASE_URL . '/staff.php');
            }
        }
    }
}

// Actions GET delete
if ($action === 'delete' && $id) {
    if ($id === ($_SESSION['user_id'] ?? 0)) {
        flash('error', 'Você não pode remover a si mesmo.');
        redirect(BASE_URL . '/staff.php');
    }
    $pdo->prepare('DELETE FROM users WHERE id=? AND company_id=?')->execute([$id, $companyId]);
    flash('success', 'Usuário removido.');
    redirect(BASE_URL . '/staff.php');
}

include __DIR__ . '/views/partials/header.php';
if ($msg = get_flash('success')) {
    echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($msg) . '</div>';
}
if ($msg = get_flash('error')) {
    echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($msg) . '</div>';
}
?>
<?php if ($action === 'list'): ?>
    <?php
    $stmt = $pdo->prepare('SELECT id, nome, email, role, created_at FROM users WHERE company_id=? ORDER BY created_at DESC');
    $stmt->execute([$companyId]);
    $users = $stmt->fetchAll();
    ?>
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h1 class="text-2xl font-semibold">Equipe / Usuários</h1>
                <p class="text-sm text-slate-600 dark:text-slate-400">Gerencie os usuários da empresa.</p>
            </div>
            <a href="<?= BASE_URL ?>/staff.php?action=create" class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                + Novo usuário
            </a>
        </div>
        <div class="overflow-hidden border border-slate-200 dark:border-slate-700 rounded-lg">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                    <tr>
                        <th class="px-4 py-2 text-left">Nome</th>
                        <th class="px-4 py-2 text-left">E-mail</th>
                        <th class="px-4 py-2 text-left">Papel</th>
                        <th class="px-4 py-2 text-left">Criado</th>
                        <th class="px-4 py-2 text-left">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-4 py-2 font-medium"><?= sanitize($u['nome']) ?></td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300"><?= sanitize($u['email']) ?></td>
                            <td class="px-4 py-2">
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="px-2 py-1 rounded text-xs bg-indigo-100 text-indigo-700">admin</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 rounded text-xs bg-slate-100 text-slate-700">user</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2 text-slate-600 dark:text-slate-300"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td class="px-4 py-2 text-sm">
                                <a class="text-indigo-600 hover:underline" href="<?= BASE_URL ?>/staff.php?action=edit&id=<?= $u['id'] ?>">Editar</a>
                                <?php if ($u['id'] !== ($_SESSION['user_id'] ?? 0)): ?>
                                    <a class="text-red-600 hover:underline ml-2" href="<?= BASE_URL ?>/staff.php?action=delete&id=<?= $u['id'] ?>" onclick="return confirm('Remover este usuário?');">Remover</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" class="px-4 py-4 text-center text-slate-500">Nenhum usuário.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($action === 'create'): ?>
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm max-w-2xl">
        <h1 class="text-2xl font-semibold mb-4">Novo usuário</h1>
        <?php if ($flashError) echo '<div class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($flashError) . '</div>'; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Nome</label>
                <input name="nome" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">E-mail</label>
                <input name="email" type="email" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Senha</label>
                <input name="senha" type="password" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Papel</label>
                <select name="role" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                    <option value="">Selecione</option>
                    <option value="admin">admin</option>
                    <option value="user">user</option>
                </select>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Salvar</button>
                <a href="<?= BASE_URL ?>/staff.php" class="px-4 py-2 rounded border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200">Voltar</a>
            </div>
        </form>
    </div>
<?php elseif ($action === 'edit' && $id): ?>
    <?php
    $user = load_user($pdo, $companyId, $id);
    if (!$user) {
        echo '<div class="p-4 rounded bg-red-50 text-red-700 border border-red-200">Usuário não encontrado. <a class="text-indigo-600 hover:underline" href="' . BASE_URL . '/staff.php">Voltar</a></div>';
    } else {
    ?>
    <div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm max-w-2xl">
        <h1 class="text-2xl font-semibold mb-4">Editar usuário</h1>
        <?php if ($flashError) echo '<div class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($flashError) . '</div>'; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Nome</label>
                <input name="nome" value="<?= sanitize($user['nome']) ?>" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">E-mail</label>
                <input name="email" value="<?= sanitize($user['email']) ?>" type="email" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Nova senha (opcional)</label>
                <input name="senha" type="password" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" placeholder="Deixe em branco para manter">
            </div>
            <div>
                <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Papel</label>
                <select name="role" class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                    <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>user</option>
                </select>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Salvar</button>
                <a href="<?= BASE_URL ?>/staff.php" class="px-4 py-2 rounded border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200">Voltar</a>
            </div>
        </form>
    </div>
    <?php } ?>
<?php endif; ?>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
