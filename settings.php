<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'nome_fantasia' => trim($_POST['nome_fantasia'] ?? ''),
        'razao_social' => trim($_POST['razao_social'] ?? ''),
        'slug' => trim($_POST['slug'] ?? ''),
        'whatsapp_principal' => trim($_POST['whatsapp_principal'] ?? ''),
        'instagram_usuario' => trim($_POST['instagram_usuario'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
    ];
    $logoPath = null;
    $faviconPath = null;

    if (!empty($_FILES['logo']['name'])) {
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['logo']['name']);
        $dest = __DIR__ . '/uploads/' . $filename;
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
            $logoPath = '/uploads/' . $filename;
        }
    }
    if (!empty($_FILES['favicon']['name'])) {
        $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['favicon']['name']);
        $dest = __DIR__ . '/uploads/' . $filename;
        if (move_uploaded_file($_FILES['favicon']['tmp_name'], $dest)) {
            $faviconPath = '/uploads/' . $filename;
        }
    }

    $sql = 'UPDATE companies SET nome_fantasia=?, razao_social=?, slug=?, whatsapp_principal=?, instagram_usuario=?, email=?, updated_at=NOW()';
    $params = [$data['nome_fantasia'], $data['razao_social'], $data['slug'], $data['whatsapp_principal'], $data['instagram_usuario'], $data['email']];
    if ($logoPath) { $sql .= ', logo=?'; $params[] = $logoPath; $_SESSION['company_logo'] = $logoPath; }
    if ($faviconPath) { $sql .= ', favicon=?'; $params[] = $faviconPath; $_SESSION['company_favicon'] = $faviconPath; }
    $sql .= ' WHERE id=?';
    $params[] = $companyId;
    $pdo->prepare($sql)->execute($params);

    $_SESSION['company_slug'] = $data['slug'];
    $_SESSION['company_name'] = $data['nome_fantasia'];
    $_SESSION['company_favicon'] = $faviconPath ?: ($_SESSION['company_favicon'] ?? '');

    flash('success', 'Dados da empresa atualizados.');
    redirect('/settings.php');
}

$stmt = $pdo->prepare('SELECT * FROM companies WHERE id=?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

include __DIR__ . '/views/partials/header.php';
if ($msg = get_flash('success')) {
    echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($msg) . '</div>';
}
?>
<div class="space-y-6 max-w-5xl">
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
        <h1 class="text-2xl font-semibold mb-4">Branding (logo e favicon)</h1>
        <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-1">
                <label class="block text-sm text-slate-600 mb-1">Logo</label>
                <input type="file" name="logo" class="w-full rounded border-slate-300">
                <p class="text-xs text-slate-500 mt-1">PNG fundo transparente, 128x128 ou 256x256 px. Aparece no topo e na tela de login.</p>
                <?php if (!empty($company['logo'])): ?>
                    <img src="<?= sanitize($company['logo']) ?>" class="mt-2 h-16 rounded object-contain bg-slate-50 border border-slate-200 p-2">
                <?php endif; ?>
            </div>
            <div class="md:col-span-1">
                <label class="block text-sm text-slate-600 mb-1">Favicon</label>
                <input type="file" name="favicon" class="w-full rounded border-slate-300">
                <p class="text-xs text-slate-500 mt-1">PNG quadrado 32x32 ou 64x64 px. Aparece na aba do navegador.</p>
                <?php if (!empty($company['favicon'])): ?>
                    <img src="<?= sanitize($company['favicon']) ?>" class="mt-2 h-10 w-10 rounded object-contain bg-slate-50 border border-slate-200 p-1">
                <?php endif; ?>
            </div>
            <div class="md:col-span-2 border-t border-slate-100 pt-4">
                <p class="text-sm font-semibold text-slate-700 mb-2">Pré-visualização</p>
                <div class="flex items-center gap-3">
                    <?php $previewLogo = $company['logo'] ?? ''; ?>
                    <?php if (!empty($previewLogo)): ?>
                        <img src="<?= sanitize($previewLogo) ?>" class="h-12 w-12 rounded-full border border-slate-200 object-cover">
                    <?php else: ?>
                        <div class="h-12 w-12 rounded-full bg-indigo-600 text-white flex items-center justify-center font-semibold"><?= strtoupper(substr($company['nome_fantasia'] ?? 'A',0,2)) ?></div>
                    <?php endif; ?>
                    <div class="text-sm text-slate-600">
                        <p><?= sanitize($company['nome_fantasia']) ?></p>
                        <p class="text-xs text-slate-500">Topo do painel e tela de login</p>
                    </div>
                </div>
            </div>
            <div class="md:col-span-2 flex justify-end">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Salvar branding</button>
            </div>
        </form>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
        <h2 class="text-xl font-semibold mb-4">Dados da empresa</h2>
        <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Nome fantasia</label>
                <input name="nome_fantasia" value="<?= sanitize($company['nome_fantasia']) ?>" class="w-full rounded border-slate-300" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Razão social</label>
                <input name="razao_social" value="<?= sanitize($company['razao_social']) ?>" class="w-full rounded border-slate-300">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Slug (loja)</label>
                <input name="slug" value="<?= sanitize($company['slug']) ?>" class="w-full rounded border-slate-300" required>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">WhatsApp principal</label>
                <input name="whatsapp_principal" value="<?= sanitize($company['whatsapp_principal']) ?>" class="w-full rounded border-slate-300" placeholder="Ex.: 5599999999999 (E.164)">
                <p class="text-xs text-slate-500 mt-1">Formato E.164 (DDI+DDD+número), sem espaços. Ex.: 5599999999999.</p>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Instagram</label>
                <input name="instagram_usuario" value="<?= sanitize($company['instagram_usuario']) ?>" class="w-full rounded border-slate-300" placeholder="Ex.: minhaconta ou @minhaconta">
                <p class="text-xs text-slate-500 mt-1">Informe apenas o usuário (com ou sem @). Usado nos links de atendimento.</p>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">E-mail</label>
                <input name="email" value="<?= sanitize($company['email']) ?>" type="email" class="w-full rounded border-slate-300">
            </div>
            <div class="md:col-span-2 flex justify-end">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Salvar dados</button>
            </div>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
            <p class="text-sm text-slate-500">ID da empresa</p>
            <p class="text-xl font-semibold"><?= (int)$company['id'] ?></p>
            <p class="text-sm text-slate-500 mt-3">Slug da empresa</p>
            <p class="text-lg font-semibold"><?= sanitize($company['slug']) ?></p>
            <p class="text-xs text-slate-500 mt-1">Use este slug para links públicos e chamadas da API multiempresa.</p>
        </div>
        <div class="p-4 rounded-lg border border-emerald-200 bg-emerald-50">
            <p class="text-sm font-semibold text-emerald-700">Atendimento por IA</p>
            <p class="text-sm text-emerald-800 mt-1">A camada JSON em /api permite que agentes (WhatsApp/Instagram via GPT) busquem clientes, registrem atendimentos e criem pedidos.</p>
            <p class="text-sm mt-2"><span class="font-semibold">Token da API:</span> <span class="font-mono"><?= sanitize(API_TOKEN_IA) ?></span></p>
            <p class="text-xs text-emerald-800 mt-1">Envie este token no parâmetro <code>token</code> de cada chamada JSON.</p>
        </div>
    </div>
    <div class="mt-2 text-sm text-slate-600 space-y-1">
        <p><span class="font-semibold">Loja pública:</span> <a class="text-indigo-600 hover:underline" target="_blank" href="/loja.php?empresa=<?= sanitize($company['slug']) ?>">/loja.php?empresa=<?= sanitize($company['slug']) ?></a></p>
    </div>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
