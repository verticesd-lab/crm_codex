<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();
require_admin();

$pdo       = get_pdo();
$companyId = current_company_id();

if (!$companyId) {
    echo 'Empresa não encontrada na sessão.';
    exit;
}

$action = $_GET['action'] ?? 'list';

// -----------------------------------------------------------------------------
// Função utilitária para buscar 1 usuário da empresa
// -----------------------------------------------------------------------------
function find_user(PDO $pdo, int $companyId, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

// -----------------------------------------------------------------------------
// PROCESSAMENTO DE FORMULÁRIOS (CREATE / EDIT / DELETE)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senha = trim($_POST['senha'] ?? '');
        $role  = trim($_POST['role'] ?? 'user');

        if ($nome === '' || $email === '' || $senha === '') {
            flash('error', 'Preencha nome, e-mail e senha.');
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO users (company_id, nome, email, senha, role, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$companyId, $nome, $email, $hash, $role]);

            flash('success', 'Usuário criado com sucesso.');
            redirect('staff.php');
        }
    }

    if ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $nome  = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = trim($_POST['role'] ?? 'user');
        $senha = trim($_POST['senha'] ?? '');

        if (!$id) {
            flash('error', 'Usuário inválido.');
            redirect('staff.php');
        }

        $user = find_user($pdo, $companyId, $id);
        if (!$user) {
            flash('error', 'Usuário não encontrado.');
            redirect('staff.php');
        }

        if ($nome === '' || $email === '') {
            flash('error', 'Preencha nome e e-mail.');
        } else {
            if ($senha !== '') {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET nome = ?, email = ?, role = ?, senha = ?, updated_at = NOW()
                     WHERE id = ? AND company_id = ?'
                );
                $stmt->execute([$nome, $email, $role, $hash, $id, $companyId]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET nome = ?, email = ?, role = ?, updated_at = NOW()
                     WHERE id = ? AND company_id = ?'
                );
                $stmt->execute([$nome, $email, $role, $id, $companyId]);
            }

            flash('success', 'Usuário atualizado com sucesso.');
            redirect('staff.php');
        }
    }
}

// DELETE via GET (simples, com confirmação no front)
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);

    if ($id) {
        // opcional: impedir que o usuário exclua a si mesmo
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $id) {
            flash('error', 'Você não pode excluir o próprio usuário logado.');
            redirect('staff.php');
        }

        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);

        flash('success', 'Usuário removido com sucesso.');
    } else {
        flash('error', 'Usuário inválido.');
    }

    redirect('staff.php');
}

// -----------------------------------------------------------------------------
// CARREGAR DADOS PARA AS TELAS (LIST / CREATE / EDIT)
// -----------------------------------------------------------------------------
$flashSuccess = get_flash('success');
$flashError   = get_flash('error');

include __DIR__ . '/views/partials/header.php';
?>

<div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">Equipe / Usuários</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">
                Gerencie os usuários da sua empresa. Apenas administradores têm acesso a esta área.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/staff.php?action=create"
           class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
            + Novo usuário
        </a>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200" data-flash>
            <?= sanitize($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200" data-flash>
            <?= sanitize($flashError) ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'create'): ?>

        <!-- Formulário de novo usuário -->
        <form method="POST" class="max-w-xl space-y-4">
            <input type="hidden" name="action" value="create">

            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Nome</label>
                <input name="nome" class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>

            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">E-mail</label>
                <input type="email" name="email" class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>

            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Senha</label>
                <input type="password" name="senha" class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" required>
            </div>

            <div>
                <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Papel</label>
                <select name="role" class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                    <option value="user">Usuário</option>
                    <option value="admin">Admin</option>
                </select>
            </div>

            <div class="flex items-center gap-3">
                <button class="px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                    Salvar
                </button>
                <a href="<?= BASE_URL ?>/staff.php" class="text-sm text-slate-600 dark:text-slate-300 hover:underline">
                    Cancelar
                </a>
            </div>
        </form>

    <?php elseif ($action === 'edit'): ?>

        <?php
        $id   = (int)($_GET['id'] ?? 0);
        $user = $id ? find_user($pdo, $companyId, $id) : null;
        ?>

        <?php if (!$user): ?>

            <p class="text-red-600">Usuário não encontrado.</p>
            <a href="<?= BASE_URL ?>/staff.php" class="text-sm text-indigo-600 hover:underline">
                Voltar para a lista
            </a>

        <?php else: ?>

            <!-- Formulário de edição -->
            <form method="POST" class="max-w-xl space-y-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">

                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Nome</label>
                    <input name="nome"
                        value="<?= sanitize($user['nome']) ?>"
                        class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700"
                        required>
                </div>

                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">E-mail</label>
                    <input type="email" name="email"
                        value="<?= sanitize($user['email']) ?>"
                        class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700"
                        required>
                </div>

                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">
                        Nova senha <span class="text-xs text-slate-500">(deixe em branco para manter)</span>
                    </label>
                    <input type="password" name="senha"
                        class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                </div>

                <div>
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">Papel</label>
                    <select name="role" class="mt-1 w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                        <option value="user"  <?= $user['role'] === 'user'  ? 'selected' : '' ?>>Usuário</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>

                <div class="flex items-center gap-3">
                    <button class="px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                        Salvar alterações
                    </button>
                    <a href="<?= BASE_URL ?>/staff.php" class="text-sm text-slate-600 dark:text-slate-300 hover:underline">
                        Cancelar
                    </a>
                </div>
            </form>

        <?php endif; ?>

    <?php else: ?>

        <?php
        // LISTAGEM
        $stmt = $pdo->prepare('SELECT id, nome, email, role, created_at FROM users WHERE company_id = ? ORDER BY created_at DESC');
        $stmt->execute([$companyId]);
        $users = $stmt->fetchAll();
        ?>

        <div class="overflow-hidden border border-slate-200 dark:border-slate-800 rounded-lg">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                    <tr>
                        <th class="px-3 py-2 text-left">Nome</th>
                        <th class="px-3 py-2 text-left">E-mail</th>
                        <th class="px-3 py-2 text-left">Papel</th>
                        <th class="px-3 py-2 text-left">Criado em</th>
                        <th class="px-3 py-2 text-left">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr class="border-t border-slate-100 dark:border-slate-800">
                        <td class="px-3 py-2"><?= sanitize($u['nome']) ?></td>
                        <td class="px-3 py-2 text-slate-600 dark:text-slate-300"><?= sanitize($u['email']) ?></td>
                        <td class="px-3 py-2">
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-indigo-100 text-indigo-700">Admin</span>
                            <?php else: ?>
                                <span class="px-2 py-1 rounded-full text-xs bg-slate-100 text-slate-700">Usuário</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-slate-600 dark:text-slate-300">
                            <?= $u['created_at'] ? date('d/m/Y H:i', strtotime($u['created_at'])) : '-' ?>
                        </td>
                        <td class="px-3 py-2 space-x-2">
                            <a href="<?= BASE_URL ?>/staff.php?action=edit&id=<?= (int)$u['id'] ?>"
                               class="text-indigo-600 hover:underline text-xs">Editar</a>
                            <a href="<?= BASE_URL ?>/staff.php?action=delete&id=<?= (int)$u['id'] ?>"
                               class="text-red-600 hover:underline text-xs"
                               onclick="return confirm('Remover este usuário?');">
                                Remover
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="5" class="px-3 py-4 text-center text-slate-500">
                            Nenhum usuário cadastrado ainda.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
