<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

if (!$companyId) {
    echo 'Empresa nao encontrada na sessao.';
    exit;
}

$errors  = [];
$success = null;

/** Carrega empresa */
$company = null;
try {
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $company = null;
}

/** Sucesso via PRG */
if (isset($_GET['ok'])) {
    $ok = (string)$_GET['ok'];
    if ($ok === 'created') $success = 'Servico criado com sucesso.';
    if ($ok === 'updated') $success = 'Servico atualizado com sucesso.';
    if ($ok === 'toggled') $success = 'Status do servico alterado.';
}

/** Helpers locais */
function normalize_service_key(string $key): string {
    $key = strtolower(trim($key));
    $key = preg_replace('/\s+/', '_', $key);
    $key = preg_replace('/[^a-z0-9_\-]/', '_', $key);
    $key = preg_replace('/_+/', '_', $key);
    $key = trim($key, '_');
    return $key;
}

function to_price($v): float {
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' '], '', $s);
    // suporta "45,00" e "45.00"
    if (str_contains($s, ',') && str_contains($s, '.')) {
        // se vier "1.234,56" -> remove milhar
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    return (float)$s;
}

function redirect_ok(string $code): void {
    header('Location: ' . BASE_URL . '/services_admin.php?ok=' . urlencode($code));
    exit;
}

/** POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $serviceKey = normalize_service_key($_POST['service_key'] ?? '');
            $label      = trim($_POST['label'] ?? '');
            $price      = to_price($_POST['price'] ?? '0');
            $duration   = (int)($_POST['duration_minutes'] ?? 30);
            $isActive   = isset($_POST['is_active']) ? 1 : 0;

            if ($serviceKey === '') $errors[] = 'Informe a chave (key) do servico.';
            if ($label === '') $errors[] = 'Informe o nome do servico.';
            if ($price < 0) $errors[] = 'Valor nao pode ser negativo.';
            if ($duration < 5) $errors[] = 'Duracao deve ser no minimo 5 minutos.';

            if (empty($errors)) {
                $ins = $pdo->prepare('
                    INSERT INTO services (company_id, service_key, label, price, duration_minutes, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ');
                $ins->execute([$companyId, $serviceKey, $label, $price, $duration, $isActive]);
                redirect_ok('created');
            }
        }

        if ($action === 'update') {
            $id       = (int)($_POST['id'] ?? 0);
            $label    = trim($_POST['label'] ?? '');
            $price    = to_price($_POST['price'] ?? '0');
            $duration = (int)($_POST['duration_minutes'] ?? 30);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($id <= 0) $errors[] = 'Servico invalido.';
            if ($label === '') $errors[] = 'Informe o nome do servico.';
            if ($price < 0) $errors[] = 'Valor nao pode ser negativo.';
            if ($duration < 5) $errors[] = 'Duracao deve ser no minimo 5 minutos.';

            if (empty($errors)) {
                $upd = $pdo->prepare('
                    UPDATE services
                    SET label = ?, price = ?, duration_minutes = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ');
                $upd->execute([$label, $price, $duration, $isActive, $id, $companyId]);
                redirect_ok('updated');
            }
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) $errors[] = 'Servico invalido.';

            if (empty($errors)) {
                $pdo->prepare('
                    UPDATE services
                    SET is_active = IF(is_active=1,0,1), updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ')->execute([$id, $companyId]);

                redirect_ok('toggled');
            }
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();

        // erro de duplicidade da key (uniq_company_service_key)
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'uniq_company_service_key') !== false) {
            $errors[] = 'Essa key ja existe. Use outra (ex: corte_2).';
        } else {
            $errors[] = 'Erro ao salvar. Verifique se a migration foi aplicada.';
        }
    }
}

/** Lista serviços */
$services = [];
try {
    $stmt = $pdo->prepare('
        SELECT id, service_key, label, price, duration_minutes, is_active
        FROM services
        WHERE company_id = ?
        ORDER BY is_active DESC, label ASC
    ');
    $stmt->execute([$companyId]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Tabela services nao encontrada. Aplique a migration.';
}

include __DIR__ . '/views/partials/header.php';
?>

<main class="flex-1 bg-slate-100 min-h-screen">
  <div class="max-w-5xl mx-auto px-6 py-6 space-y-6">

    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Servicos da barbearia</h1>
        <p class="text-sm text-slate-500 mt-1">Adicionar/remover e ajustar valor/tempo dos servicos.</p>
      </div>
      <div class="text-right">
        <p class="text-xs uppercase tracking-wide text-slate-400">Empresa</p>
        <p class="font-semibold text-slate-800"><?= sanitize($company['nome_fantasia'] ?? '') ?></p>
      </div>
    </header>

    <?php if (!empty($errors)): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl text-sm space-y-1">
        <?php foreach ($errors as $msg): ?><p><?= sanitize($msg) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-sm">
        <?= sanitize($success) ?>
      </div>
    <?php endif; ?>

    <!-- Criar novo serviço -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 space-y-4">
      <h2 class="text-lg font-semibold text-slate-900">Adicionar servico</h2>

      <form method="post" class="grid grid-cols-1 md:grid-cols-5 gap-3">
        <input type="hidden" name="action" value="create">

        <div class="md:col-span-1">
          <label class="block text-sm font-medium text-slate-700 mb-1">Key</label>
          <input name="service_key" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                 placeholder="ex: corte" required>
          <p class="text-[11px] text-slate-500 mt-1">Sem espaço. Usado internamente.</p>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-slate-700 mb-1">Nome</label>
          <input name="label" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                 placeholder="Corte" required>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Valor (R$)</label>
          <input name="price" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                 placeholder="45,00">
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Tempo (min)</label>
          <input name="duration_minutes" type="number" min="5" step="5" value="30"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="is_active" checked class="h-4 w-4 rounded border-slate-300">
            Ativo
          </label>
        </div>

        <div class="md:col-span-5 flex items-center gap-3">
          <button class="inline-flex items-center justify-center rounded-lg bg-indigo-600 text-white text-sm font-semibold px-4 py-2 hover:bg-indigo-500">
            Salvar
          </button>
          <a href="<?= BASE_URL ?>/calendar_barbearia.php" class="text-sm text-slate-600 underline">
            Voltar para agenda
          </a>
        </div>
      </form>
    </section>

    <!-- Lista / edição -->
    <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 space-y-4">
      <h2 class="text-lg font-semibold text-slate-900">Servicos cadastrados</h2>

      <?php if (empty($services)): ?>
        <p class="text-sm text-slate-500">Nenhum servico cadastrado ainda.</p>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($services as $s): ?>
            <div class="border border-slate-200 rounded-xl p-3">
              <!-- UPDATE form -->
              <form method="post" class="grid grid-cols-1 md:grid-cols-7 gap-2 items-end">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">

                <div class="md:col-span-1">
                  <label class="block text-xs font-medium text-slate-600 mb-1">Key</label>
                  <div class="text-sm font-mono text-slate-700"><?= sanitize($s['service_key']) ?></div>
                </div>

                <div class="md:col-span-2">
                  <label class="block text-xs font-medium text-slate-600 mb-1">Nome</label>
                  <input name="label" value="<?= sanitize($s['label']) ?>"
                         class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                </div>

                <div class="md:col-span-1">
                  <label class="block text-xs font-medium text-slate-600 mb-1">Valor</label>
                  <input name="price" value="<?= sanitize((string)$s['price']) ?>"
                         class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>

                <div class="md:col-span-1">
                  <label class="block text-xs font-medium text-slate-600 mb-1">Minutos</label>
                  <input name="duration_minutes" type="number" min="5" step="5"
                         value="<?= (int)$s['duration_minutes'] ?>"
                         class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>

                <div class="md:col-span-1">
                  <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" <?= ((int)$s['is_active'] === 1) ? 'checked' : '' ?>
                           class="h-4 w-4 rounded border-slate-300">
                    Ativo
                  </label>
                </div>

                <div class="md:col-span-1 flex gap-2">
                  <button class="flex-1 rounded-lg bg-indigo-600 text-white text-sm font-semibold px-3 py-2 hover:bg-indigo-500">
                    Atualizar
                  </button>
                </div>
              </form>

              <!-- TOGGLE form (separado, sem bug) -->
              <div class="mt-2 flex items-center justify-end">
                <form method="post" class="m-0">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button class="rounded-lg border border-slate-300 text-slate-700 text-sm font-semibold px-3 py-2 hover:border-slate-400">
                    Ativar/Desativar
                  </button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </div>
</main>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
