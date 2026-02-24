<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();
require_admin();

$pdo       = get_pdo();
$companyId = current_company_id();

$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([$companyId]);
$company = $stmt->fetch();
if (!$company) { echo 'Empresa não encontrada.'; exit; }

/* ── Garante coluna modules_config ── */
try {
    $cols = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('modules_config', $cols)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN modules_config JSON DEFAULT NULL");
    }
} catch(Throwable $e) {}

/* ── Definição de todos os módulos disponíveis ── */
$ALL_MODULES = [
    // [ key, label, descrição, arquivo, ícone, grupo, sempre_ativo ]
    ['dashboard',         'Dashboard',           'Visão geral com KPIs, vendas e timeline.',             'index.php',              '📊', 'core',       true],
    ['caixa',             'Caixa (PDV)',          'Terminal de ponto de venda para registrar vendas.',    'pos.php',                '🛒', 'vendas',     false],
    ['clientes',          'Clientes',             'Gestão de clientes, histórico e perfil.',              'clients.php',            '👥', 'core',       false],
    ['funil',             'Funil / Oportunidades','Pipeline de vendas e leads.',                          'opportunities.php',      '🎯', 'vendas',     false],
    ['atendimento',       'Atendimento',          'Histórico de atendimentos via WhatsApp/IA.',           'atendimento.php',        '💬', 'core',       false],
    ['produtos',          'Produtos/Serviços',    'Catálogo de produtos e serviços.',                     'products.php',           '📦', 'vendas',     false],
    ['cadastro_inteligente','Cadastro Inteligente','Importação de mercadoria via CSV/planilha.',          'products_imports.php',   '📤', 'vendas',     false],
    ['pedidos',           'Pedidos',              'Gestão de pedidos e histórico de vendas.',             'orders.php',             '🧾', 'vendas',     false],
    ['promocoes',         'Promoções',            'Criação e gestão de campanhas promocionais.',          'promotions.php',         '🏷️', 'marketing',  false],
    ['kpis',              'KPIs',                 'Indicadores de performance e metas.',                  'kpis.php',               '📈', 'analytics',  false],
    ['analytics',         'Analytics',            'Análise de dados e relatórios avançados.',             'analytics.php',          '📉', 'analytics',  false],
    ['canais',            'Canais',               'Configuração de integrações (WhatsApp, Instagram).',   'integrations.php',       '🔗', 'config',     false],
    ['insights_ia',       'Insights IA',          'Sugestões e análises geradas por inteligência artificial.','insights.php',       '🤖', 'analytics',  false],
    ['agenda',            'Agenda',               'Calendário geral de compromissos.',                    'calendar.php',           '📅', 'agenda',     false],
    ['agenda_barbearia',  'Agenda Barbearia',     'Agendamentos específicos para barbearia.',             'calendar_barbearia.php', '✂️', 'agenda',     false],
    ['servicos_barbearia','Serviços Barbearia',   'Catálogo de serviços da barbearia.',                   'services_admin.php',     '💈', 'agenda',     false],
    ['equipe',            'Equipe',               'Gestão de funcionários e permissões.',                 'staff.php',              '👤', 'config',     false],
    ['configuracoes',     'Configurações',        'Configurações da empresa e do sistema.',               'settings.php',           '⚙️', 'config',     true],
];

/* ── Módulos ativos (lê do banco) ── */
$modulesJson = $company['modules_config'] ?? null;
$activeModules = [];
if ($modulesJson) {
    $decoded = json_decode($modulesJson, true);
    if (is_array($decoded)) $activeModules = $decoded;
}
// Se nunca foi configurado, ativa todos por padrão
if (empty($activeModules)) {
    foreach($ALL_MODULES as $m) $activeModules[$m[0]] = true;
}

/* ── POST: salvar configurações da empresa ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $nomeFantasia     = trim($_POST['nome_fantasia'] ?? '');
    $razaoSocial      = trim($_POST['razao_social'] ?? '');
    $whatsapp         = trim($_POST['whatsapp_principal'] ?? '');
    $instagramUsuario = trim($_POST['instagram_usuario'] ?? '');
    $email            = trim($_POST['email'] ?? '');

    if ($nomeFantasia === '') {
        flash('error', 'Informe o nome fantasia.');
        redirect('settings.php?tab=empresa');
    }

    $logoPath    = $company['logo'] ?? null;
    $faviconPath = $company['favicon'] ?? null;

    foreach(['logo','favicon'] as $field) {
        if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $ext  = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            $dest = __DIR__ . '/uploads/' . $field . '_company_' . $companyId . '_' . time() . '.' . $ext;
            if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0777, true);
            if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
                $rel = 'uploads/' . basename($dest);
                if ($field === 'logo') { $logoPath = $rel; $_SESSION['company_logo'] = $rel; }
                else $faviconPath = $rel;
            }
        }
    }

    $pdo->prepare('UPDATE companies SET nome_fantasia=?,razao_social=?,whatsapp_principal=?,instagram_usuario=?,email=?,logo=?,favicon=?,updated_at=NOW() WHERE id=?')
        ->execute([$nomeFantasia,$razaoSocial,$whatsapp,$instagramUsuario,$email,$logoPath,$faviconPath,$companyId]);
    $_SESSION['company_name'] = $nomeFantasia;

    flash('success','Configurações salvas com sucesso.');
    redirect('settings.php?tab=empresa');
}

/* ── POST: salvar módulos ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_modules'])) {
    $newModules = [];
    foreach($ALL_MODULES as $m) {
        $key = $m[0];
        $alwaysOn = $m[6];
        $newModules[$key] = $alwaysOn ? true : isset($_POST['mod_'.$key]);
    }
    $pdo->prepare('UPDATE companies SET modules_config=?,updated_at=NOW() WHERE id=?')
        ->execute([json_encode($newModules), $companyId]);

    // Atualiza sessão
    $_SESSION['modules_config'] = $newModules;

    flash('success','Módulos atualizados. O menu foi atualizado.');
    redirect('settings.php?tab=modulos');
}

$flashSuccess = get_flash('success');
$flashError   = get_flash('error');
$activeTab    = $_GET['tab'] ?? 'empresa';

/* ── Grupos de módulos ── */
$groups = [
    'core'      => ['label'=>'Core','icon'=>'⭐','desc'=>'Funcionalidades essenciais do sistema'],
    'vendas'    => ['label'=>'Vendas & Estoque','icon'=>'🛍️','desc'=>'PDV, produtos, pedidos e funil de vendas'],
    'marketing' => ['label'=>'Marketing','icon'=>'📣','desc'=>'Promoções e campanhas'],
    'analytics' => ['label'=>'Analytics & IA','icon'=>'📊','desc'=>'Relatórios, KPIs e inteligência artificial'],
    'agenda'    => ['label'=>'Agenda','icon'=>'📅','desc'=>'Calendários e agendamentos'],
    'config'    => ['label'=>'Configurações','icon'=>'⚙️','desc'=>'Canais, equipe e sistema'],
];

// Conta módulos ativos por grupo
$groupCounts = [];
foreach($ALL_MODULES as $m) {
    $g = $m[4+1]; // grupo = índice 5
    if (!isset($groupCounts[$g])) $groupCounts[$g] = ['total'=>0,'active'=>0];
    $groupCounts[$g]['total']++;
    if (!empty($activeModules[$m[0]])) $groupCounts[$g]['active']++;
}

include __DIR__ . '/views/partials/header.php';
?>

<style>
/* ── Settings UI ── */
.set-wrap { max-width:960px; }

/* Tabs */
.set-tabs { display:flex; gap:.25rem; border-bottom:2px solid #e2e8f0; margin-bottom:1.5rem; }
.set-tab { padding:.65rem 1.25rem; font-size:.85rem; font-weight:600; color:#64748b; cursor:pointer; text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .15s; border-radius:6px 6px 0 0; white-space:nowrap; }
.set-tab:hover { color:#6366f1; background:#f5f3ff; }
.set-tab.active { color:#6366f1; border-bottom-color:#6366f1; background:#f5f3ff; }

/* Cards */
.set-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.set-card-header { padding:.85rem 1.25rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; }
.set-card-header h2 { font-size:.9rem; font-weight:700; color:#0f172a; }
.set-card-header p  { font-size:.75rem; color:#94a3b8; margin-top:.1rem; }
.set-card-body { padding:1.25rem; }

/* Flash */
.flash-ok  { padding:.75rem 1rem; border-radius:9px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.82rem; margin-bottom:1.25rem; }
.flash-err { padding:.75rem 1rem; border-radius:9px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.82rem; margin-bottom:1.25rem; }

/* Fields */
.field { margin-bottom:1rem; }
.field label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin-bottom:.35rem; }
.fi { width:100%; padding:.55rem .85rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.875rem; background:#f8fafc; color:#0f172a; outline:none; transition:border-color .15s; }
.fi:focus { border-color:#6366f1; background:#fff; }
.fi-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
.fi-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:.85rem; }

/* Logo upload */
.logo-area { display:flex; align-items:center; gap:1.25rem; padding:1rem; border:1.5px dashed #e2e8f0; border-radius:12px; background:#f8fafc; }
.logo-preview { width:72px; height:72px; border-radius:12px; background:#e2e8f0; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; }
.logo-preview img { width:100%; height:100%; object-fit:cover; }

/* Btn */
.btn-save { padding:.65rem 1.5rem; background:#6366f1; color:#fff; border:none; border-radius:8px; font-size:.875rem; font-weight:700; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#4f46e5; }
.btn-save.green { background:#16a34a; }
.btn-save.green:hover { background:#15803d; }

/* ── Module Manager ── */
.mod-group { margin-bottom:1.5rem; }
.mod-group-header { display:flex; align-items:center; gap:.6rem; padding:.6rem .85rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px 10px 0 0; border-bottom:none; }
.mod-group-icon { font-size:1rem; }
.mod-group-label { font-size:.82rem; font-weight:700; color:#0f172a; }
.mod-group-desc { font-size:.72rem; color:#94a3b8; margin-left:.25rem; }
.mod-group-count { margin-left:auto; font-size:.72rem; font-weight:700; color:#6366f1; background:#ede9fe; padding:.15rem .5rem; border-radius:20px; }

.mod-list { border:1px solid #e2e8f0; border-radius:0 0 10px 10px; overflow:hidden; }
.mod-item { display:flex; align-items:center; gap:1rem; padding:.85rem 1rem; border-bottom:1px solid #f1f5f9; transition:background .1s; }
.mod-item:last-child { border-bottom:none; }
.mod-item:hover { background:#fafafe; }
.mod-item.always-on { opacity:.7; }

.mod-icon { font-size:1.1rem; width:32px; text-align:center; flex-shrink:0; }
.mod-info { flex:1; min-width:0; }
.mod-name { font-size:.875rem; font-weight:600; color:#0f172a; }
.mod-desc { font-size:.72rem; color:#94a3b8; margin-top:.1rem; }
.mod-badge-always { font-size:.65rem; font-weight:700; padding:.15rem .45rem; border-radius:20px; background:#f1f5f9; color:#94a3b8; white-space:nowrap; }

/* Toggle switch */
.tog { position:relative; width:44px; height:24px; flex-shrink:0; }
.tog input { opacity:0; width:0; height:0; position:absolute; }
.tog-sl { position:absolute; inset:0; background:#e2e8f0; border-radius:24px; cursor:pointer; transition:.2s; }
.tog-sl::after { content:''; position:absolute; width:18px; height:18px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.tog input:checked + .tog-sl { background:#22c55e; }
.tog input:checked + .tog-sl::after { left:23px; }
.tog input:disabled + .tog-sl { cursor:not-allowed; background:#d1fae5; }
.tog input:disabled:checked + .tog-sl { background:#22c55e; opacity:.6; }

/* Stats bar */
.mod-stats { display:flex; gap:1rem; padding:.85rem 1.25rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:1.25rem; }
.mod-stat { text-align:center; }
.mod-stat-val { font-size:1.25rem; font-weight:800; color:#6366f1; line-height:1; }
.mod-stat-label { font-size:.65rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }

/* Preview sidebar */
.sidebar-preview { background:#1e293b; border-radius:10px; padding:.75rem; }
.sp-title { font-size:.6rem; text-transform:uppercase; letter-spacing:.08em; color:#475569; padding:.3rem .5rem; }
.sp-item { display:flex; align-items:center; gap:.5rem; padding:.4rem .6rem; border-radius:6px; font-size:.75rem; color:#94a3b8; cursor:default; }
.sp-item.active { background:#6366f1; color:#fff; }
.sp-item.on { color:#e2e8f0; }
.sp-item.off { opacity:.3; text-decoration:line-through; }
</style>

<div class="set-wrap">

  <?php if ($flashSuccess): ?><div class="flash-ok">✅ <?= sanitize($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError):   ?><div class="flash-err">⚠️ <?= sanitize($flashError) ?></div><?php endif; ?>

  <!-- Tabs -->
  <div class="set-tabs">
    <a href="?tab=empresa"  class="set-tab <?= $activeTab==='empresa' ?'active':'' ?>">🏢 Empresa</a>
    <a href="?tab=modulos"  class="set-tab <?= $activeTab==='modulos'?'active':'' ?>">🧩 Módulos do Menu</a>
    <a href="?tab=acesso"   class="set-tab <?= $activeTab==='acesso'  ?'active':'' ?>">🔐 Acesso & Segurança</a>
  </div>

  <?php /* ════════════════ TAB: EMPRESA ════════════════ */ ?>
  <?php if ($activeTab === 'empresa'): ?>

    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="save_company" value="1">

      <div style="display:grid;grid-template-columns:1fr 280px;gap:1.25rem;align-items:start;">

        <div>
          <!-- Identidade -->
          <div class="set-card">
            <div class="set-card-header">
              <h2>🏢 Identidade da Empresa</h2>
              <p>Dados que aparecem no painel, na loja pública e nos atendimentos.</p>
            </div>
            <div class="set-card-body">
              <div class="field">
                <label>Nome Fantasia *</label>
                <input class="fi" type="text" name="nome_fantasia" value="<?= sanitize($company['nome_fantasia']??'') ?>" required>
              </div>
              <div class="field">
                <label>Razão Social</label>
                <input class="fi" type="text" name="razao_social" value="<?= sanitize($company['razao_social']??'') ?>">
              </div>
              <div class="fi-grid-2" style="margin-bottom:1rem;">
                <div class="field" style="margin-bottom:0;">
                  <label>WhatsApp Principal</label>
                  <input class="fi" type="text" name="whatsapp_principal" value="<?= sanitize($company['whatsapp_principal']??'') ?>" placeholder="5565999999999">
                </div>
                <div class="field" style="margin-bottom:0;">
                  <label>Instagram</label>
                  <input class="fi" type="text" name="instagram_usuario" value="<?= sanitize($company['instagram_usuario']??'') ?>" placeholder="@sualoja">
                </div>
              </div>
              <div class="field">
                <label>E-mail</label>
                <input class="fi" type="email" name="email" value="<?= sanitize($company['email']??'') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- Logo + Favicon -->
        <div>
          <div class="set-card">
            <div class="set-card-header"><h2>🖼️ Identidade Visual</h2></div>
            <div class="set-card-body">

              <div class="field">
                <label>Logo da Empresa</label>
                <div class="logo-area">
                  <div class="logo-preview">
                    <?php if(!empty($company['logo'])): ?>
                      <img src="<?= sanitize($company['logo']) ?>" alt="Logo">
                    <?php else: ?>
                      <span style="font-size:1.5rem;">🏢</span>
                    <?php endif; ?>
                  </div>
                  <div>
                    <input type="file" name="logo" accept="image/*" style="font-size:.75rem;">
                    <p style="font-size:.68rem;color:#94a3b8;margin-top:.25rem;">JPG, PNG ou WEBP</p>
                  </div>
                </div>
              </div>

              <div class="field">
                <label>Favicon <span style="font-weight:400;color:#94a3b8;">(ícone do browser)</span></label>
                <div class="logo-area" style="padding:.75rem;">
                  <div class="logo-preview" style="width:40px;height:40px;border-radius:6px;">
                    <?php if(!empty($company['favicon'])): ?>
                      <img src="<?= sanitize($company['favicon']) ?>" alt="Favicon">
                    <?php else: ?>
                      <span style="font-size:.9rem;">🔖</span>
                    <?php endif; ?>
                  </div>
                  <input type="file" name="favicon" accept="image/*" style="font-size:.75rem;">
                </div>
              </div>

            </div>
          </div>

          <button type="submit" class="btn-save" style="width:100%;">💾 Salvar Configurações</button>
        </div>
      </div>
    </form>

  <?php /* ════════════════ TAB: MÓDULOS ════════════════ */ ?>
  <?php elseif ($activeTab === 'modulos'): ?>

    <!-- Stats -->
    <?php
    $totalMods  = count($ALL_MODULES);
    $activeCnt  = count(array_filter($activeModules));
    $alwaysOnCnt= count(array_filter($ALL_MODULES, fn($m) => $m[6]));
    ?>
    <div class="mod-stats">
      <div class="mod-stat">
        <div class="mod-stat-val"><?= $totalMods ?></div>
        <div class="mod-stat-label">Módulos disponíveis</div>
      </div>
      <div class="mod-stat">
        <div class="mod-stat-val" style="color:#22c55e;"><?= $activeCnt ?></div>
        <div class="mod-stat-label">Ativos no menu</div>
      </div>
      <div class="mod-stat">
        <div class="mod-stat-val" style="color:#94a3b8;"><?= $totalMods - $activeCnt ?></div>
        <div class="mod-stat-label">Desativados</div>
      </div>
      <div class="mod-stat" style="margin-left:auto;text-align:right;">
        <div style="font-size:.75rem;color:#94a3b8;max-width:200px;">
          Desativar um módulo o remove do menu lateral. Os dados não são apagados — você pode reativar a qualquer momento.
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 200px;gap:1.25rem;align-items:start;">

      <form method="POST" id="modules-form">
        <input type="hidden" name="save_modules" value="1">

        <?php
        // Agrupa módulos por grupo
        $byGroup = [];
        foreach($ALL_MODULES as $m) $byGroup[$m[5]][] = $m;

        foreach($byGroup as $groupKey => $mods):
          $g = $groups[$groupKey] ?? ['label'=>$groupKey,'icon'=>'📌','desc'=>''];
          $gc = $groupCounts[$groupKey] ?? ['active'=>0,'total'=>0];
        ?>
          <div class="mod-group">
            <div class="mod-group-header">
              <span class="mod-group-icon"><?= $g['icon'] ?></span>
              <span class="mod-group-label"><?= $g['label'] ?></span>
              <span class="mod-group-desc"><?= $g['desc'] ?></span>
              <span class="mod-group-count"><?= $gc['active'] ?>/<?= $gc['total'] ?></span>
            </div>
            <div class="mod-list">
              <?php foreach($mods as $m):
                [$key, $label, $desc, $file, $icon, $group, $alwaysOn] = $m;
                $isActive = !empty($activeModules[$key]);
              ?>
                <div class="mod-item <?= $alwaysOn ? 'always-on' : '' ?>">
                  <span class="mod-icon"><?= $icon ?></span>
                  <div class="mod-info">
                    <div class="mod-name"><?= $label ?></div>
                    <div class="mod-desc"><?= $desc ?></div>
                  </div>
                  <?php if($alwaysOn): ?>
                    <span class="mod-badge-always">Sempre ativo</span>
                    <input type="hidden" name="mod_<?= $key ?>" value="1">
                  <?php else: ?>
                    <label class="tog">
                      <input type="checkbox" name="mod_<?= $key ?>" value="1"
                             <?= $isActive ? 'checked' : '' ?>
                             onchange="updatePreview()">
                      <span class="tog-sl"></span>
                    </label>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:.75rem;margin-top:.5rem;">
          <button type="button" onclick="toggleAll(true)"
                  style="padding:.5rem 1rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;">
            ✅ Ativar todos
          </button>
          <button type="button" onclick="toggleAll(false)"
                  style="padding:.5rem 1rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;">
            ⬜ Desativar opcionais
          </button>
          <button type="submit" class="btn-save green" style="margin-left:auto;">
            💾 Salvar Módulos
          </button>
        </div>
      </form>

      <!-- Preview ao vivo do sidebar -->
      <div style="position:sticky;top:1rem;">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.5rem;">
          Preview do Menu
        </div>
        <div class="sidebar-preview" id="sidebar-preview">
          <!-- Renderizado pelo JS -->
        </div>
        <p style="font-size:.65rem;color:#94a3b8;margin-top:.5rem;text-align:center;">Atualiza em tempo real</p>
      </div>
    </div>

  <?php /* ════════════════ TAB: ACESSO ════════════════ */ ?>
  <?php elseif ($activeTab === 'acesso'): ?>

    <div class="set-card">
      <div class="set-card-header">
        <h2>🔐 Perfis de Acesso</h2>
        <p>Como o sistema diferencia Admin de Usuário comum.</p>
      </div>
      <div class="set-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

          <div style="border:1.5px solid #e0e7ff;border-radius:12px;padding:1.25rem;background:#f5f3ff;">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
              <span style="font-size:1.2rem;">👑</span>
              <div>
                <p style="font-size:.875rem;font-weight:700;color:#4338ca;">Admin</p>
                <p style="font-size:.68rem;color:#6366f1;">Acesso total</p>
              </div>
            </div>
            <ul style="font-size:.78rem;color:#374151;list-style:disc;padding-left:1rem;line-height:1.8;">
              <li>Acesso a todos os módulos ativos</li>
              <li>Configurações da empresa</li>
              <li>Gerenciamento de módulos</li>
              <li>Relatórios e analytics</li>
              <li>Gestão de equipe</li>
              <li>Pedidos e financeiro</li>
              <li>Caixa (PDV)</li>
            </ul>
          </div>

          <div style="border:1.5px solid #e2e8f0;border-radius:12px;padding:1.25rem;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
              <span style="font-size:1.2rem;">👤</span>
              <div>
                <p style="font-size:.875rem;font-weight:700;color:#374151;">Usuário</p>
                <p style="font-size:.68rem;color:#64748b;">Acesso limitado</p>
              </div>
            </div>
            <ul style="font-size:.78rem;color:#374151;list-style:disc;padding-left:1rem;line-height:1.8;">
              <li>Dashboard (somente leitura)</li>
              <li>Atendimento ao cliente</li>
              <li>Agenda e agendamentos</li>
              <li>Consulta de clientes</li>
              <li>Caixa (PDV) — se habilitado</li>
              <li>❌ Sem acesso a configurações</li>
              <li>❌ Sem acesso a analytics e KPIs</li>
            </ul>
          </div>
        </div>

        <div style="margin-top:1.25rem;padding:1rem;border-radius:10px;background:#fef9c3;border:1px solid #fde68a;">
          <p style="font-size:.82rem;color:#92400e;font-weight:600;">💡 Como criar usuários</p>
          <p style="font-size:.75rem;color:#92400e;margin-top:.3rem;">
            Acesse <strong>Equipe</strong> no menu lateral para cadastrar funcionários e definir se são Admin ou Usuário. 
            O nível de acesso é controlado pelo campo <code style="background:#fef3c7;padding:.1rem .3rem;border-radius:3px;">is_admin</code> na tabela de usuários.
          </p>
          <a href="staff.php" style="display:inline-block;margin-top:.6rem;padding:.4rem .85rem;background:#d97706;color:#fff;border-radius:6px;font-size:.75rem;font-weight:700;text-decoration:none;">
            → Gerenciar Equipe
          </a>
        </div>
      </div>
    </div>

    <!-- Sessão atual -->
    <div class="set-card">
      <div class="set-card-header"><h2>🔍 Sessão Atual</h2></div>
      <div class="set-card-body">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">
          <?php foreach([
            ['Usuário',  $_SESSION['user_name']    ?? '—'],
            ['Perfil',   isset($_SESSION['is_admin']) && $_SESSION['is_admin'] ? '👑 Admin' : '👤 Usuário'],
            ['Empresa',  $_SESSION['company_name'] ?? '—'],
          ] as [$l,$v]): ?>
            <div style="padding:.85rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
              <p style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;"><?= $l ?></p>
              <p style="font-size:.9rem;font-weight:700;color:#0f172a;margin-top:.25rem;"><?= sanitize($v) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  <?php endif; ?>

</div>

<script>
// ── Dados dos módulos para o preview ──
const ALL_MODULES = <?= json_encode(array_map(fn($m) => [
    'key'   => $m[0],
    'label' => $m[1],
    'icon'  => $m[4],
    'always'=> $m[6],
], $ALL_MODULES)) ?>;

function getActiveKeys() {
    const checks = document.querySelectorAll('#modules-form input[type="checkbox"]');
    const hidden  = document.querySelectorAll('#modules-form input[type="hidden"][name^="mod_"]');
    const active  = new Set();
    checks.forEach(c => { if(c.checked) active.add(c.name.replace('mod_','')); });
    hidden.forEach(h => active.add(h.name.replace('mod_','')));
    return active;
}

function updatePreview() {
    const active = getActiveKeys();
    const preview = document.getElementById('sidebar-preview');
    if (!preview) return;

    let html = '<div class="sp-title">Menu</div>';
    ALL_MODULES.forEach(m => {
        const on = active.has(m.key);
        html += `<div class="sp-item ${on?'on':'off'}">${m.icon} ${m.label}</div>`;
    });
    preview.innerHTML = html;
}

function toggleAll(state) {
    document.querySelectorAll('#modules-form input[type="checkbox"]').forEach(c => {
        if (!c.disabled) c.checked = state;
    });
    updatePreview();
}

// Init preview
document.addEventListener('DOMContentLoaded', updatePreview);
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>