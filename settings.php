<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();
require_admin(); // só admin pode editar configurações

$pdo       = get_pdo();
$companyId = current_company_id();

// busca empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

if (!$company) {
    echo 'Empresa não encontrada.';
    exit;
}

$flashError   = get_flash('error');
$flashSuccess = get_flash('success');

// processa formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomeFantasia     = trim($_POST['nome_fantasia'] ?? '');
    $razaoSocial      = trim($_POST['razao_social'] ?? '');
    $whatsapp         = trim($_POST['whatsapp_principal'] ?? '');
    $instagramUsuario = trim($_POST['instagram_usuario'] ?? '');
    $email            = trim($_POST['email'] ?? '');

    if ($nomeFantasia === '') {
        $flashError = 'Informe pelo menos o nome fantasia da empresa.';
    } else {
        // upload de logo (opcional)
        $logoPath    = $company['logo'] ?? null;
        $faviconPath = $company['favicon'] ?? null;

        if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $fileName = 'logo_company_' . $companyId . '_' . time() . '.' . strtolower($ext);
            $destDir  = __DIR__ . '/uploads';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }
            $destPath = $destDir . '/' . $fileName;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $destPath)) {
                $logoPath = 'uploads/' . $fileName;
                $_SESSION['company_logo'] = $logoPath;
            }
        }

        if (!empty($_FILES['favicon']['name']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION);
            $fileName = 'favicon_company_' . $companyId . '_' . time() . '.' . strtolower($ext);
            $destDir  = __DIR__ . '/uploads';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0777, true);
            }
            $destPath = $destDir . '/' . $fileName;
            if (move_uploaded_file($_FILES['favicon']['tmp_name'], $destPath)) {
                $faviconPath = 'uploads/' . $fileName;
            }
        }

        $update = $pdo->prepare('
            UPDATE companies
            SET nome_fantasia = ?, razao_social = ?, whatsapp_principal = ?, instagram_usuario = ?, email = ?, logo = ?, favicon = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $update->execute([
            $nomeFantasia,
            $razaoSocial,
            $whatsapp,
            $instagramUsuario,
            $email,
            $logoPath,
            $faviconPath,
            $companyId,
        ]);

        $_SESSION['company_name'] = $nomeFantasia;

        flash('success', 'Configurações atualizadas com sucesso.');
        redirect('settings.php');
    }

    if ($flashError) {
        flash('error', $flashError);
        redirect('settings.php');
    }
}

// recarrega flashes
$flashError   = get_flash('error') ?? $flashError;
$flashSuccess = get_flash('success') ?? $flashSuccess;

include __DIR__ . '/views/partials/header.php';
?>

<div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">Configurações da Empresa</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">
                Atualize as informações principais da sua empresa. Esses dados aparecem no painel, na loja pública e nos canais de atendimento.
            </p>
        </div>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="mb-4 p-3 rounded border border-emerald-300 bg-emerald-50 text-emerald-800 text-sm">
            <?= sanitize($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="mb-4 p-3 rounded border border-red-300 bg-red-50 text-red-800 text-sm">
            <?= sanitize($flashError) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="space-y-4 lg:col-span-2">
            <div>
                <label class="text-xs text-slate-500">Nome fantasia</label>
                <input name="nome_fantasia"
                       value="<?= sanitize($company['nome_fantasia'] ?? '') ?>"
                       class="mt-1 w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
            </div>
            <div>
                <label class="text-xs text-slate-500">Razão social</label>
                <input name="razao_social"
                       value="<?= sanitize($company['razao_social'] ?? '') ?>"
                       class="mt-1 w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-slate-500">WhatsApp principal</label>
                    <input name="whatsapp_principal"
                           value="<?= sanitize($company['whatsapp_principal'] ?? '') ?>"
                           placeholder="5599999999999"
                           class="mt-1 w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                </div>
                <div>
                    <label class="text-xs text-slate-500">Instagram (usuário)</label>
                    <input name="instagram_usuario"
                           value="<?= sanitize($company['instagram_usuario'] ?? '') ?>"
                           placeholder="@sualoja"
                           class="mt-1 w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                </div>
            </div>
            <div>
                <label class="text-xs text-slate-500">E-mail</label>
                <input name="email"
                       value="<?= sanitize($company['email'] ?? '') ?>"
                       class="mt-1 w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
            </div>
        </div>

        <div class="space-y-4">
            <div class="flex flex-col items-center gap-3 p-4 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
                <div class="h-20 w-20 rounded-full bg-slate-200 dark:bg-slate-800 flex items-center justify-center overflow-hidden">
                    <?php if (!empty($company['logo'])): ?>
                        <img src="<?= sanitize($company['logo']) ?>" class="h-full w-full object-cover">
                    <?php else: ?>
                        <span class="text-slate-500 text-sm">Sem logo</span>
                    <?php endif; ?>
                </div>
                <div class="text-center text-xs text-slate-500">
                    Logo da empresa (mostra no painel e na loja pública).
                </div>
                <input type="file" name="logo" class="text-xs">
            </div>

            <div class="flex flex-col gap-2 p-4 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900">
                <label class="text-xs text-slate-500">Favicon (ícone do navegador)</label>
                <?php if (!empty($company['favicon'])): ?>
                    <img src="<?= sanitize($company['favicon']) ?>" class="h-8 w-8 rounded border border-slate-300">
                <?php endif; ?>
                <input type="file" name="favicon" class="text-xs">
            </div>

            <button class="w-full mt-2 bg-indigo-600 text-white rounded py-2 text-sm font-semibold hover:bg-indigo-700">
                Salvar configurações
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
