<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();
require_admin();

$pdo       = get_pdo();
$companyId = current_company_id();

/* ── Garante coluna modules_config ── */
try {
    $cols = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('modules_config', $cols)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN modules_config JSON DEFAULT NULL");
    }
} catch(Throwable $e) {}

$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stmt->execute([$companyId]);
$company = $stmt->fetch();
if (!$company) { echo 'Empresa não encontrada.'; exit; }

/* ── Definição de todos os módulos ── */
$ALL_MODULES = [
    // [ key, label, desc, arquivo, icon, grupo, sempre_ativo ]
    ['dashboard',            'Dashboard',            'Visão geral com KPIs, vendas e timeline.',                  'index.php',              '📊', 'core',      true ],
    ['caixa',                'Caixa (PDV)',           'Terminal de ponto de venda para registrar vendas.',         'pos.php',                '🛒', 'vendas',    false],
    ['clientes',             'Clientes',             'Gestão de clientes, histórico e perfil.',                   'clients.php',            '👥', 'core',      false],
    ['funil',                'Funil / Oportunidades','Pipeline de vendas e leads.',                               'opportunities.php',      '🎯', 'vendas',    false],
    ['atendimento',          'Atendimento',          'Histórico de atendimentos via WhatsApp/IA.',                'atendimento.php',        '💬', 'core',      false],
    ['produtos',             'Produtos/Serviços',    'Catálogo de produtos e serviços.',                          'products.php',           '📦', 'vendas',    false],
    ['cadastro_inteligente', 'Cadastro Inteligente', 'Importação de mercadoria via CSV/planilha.',               'products_imports.php',   '📤', 'vendas',    false],
    ['pedidos',              'Pedidos',              'Gestão de pedidos e histórico de vendas.',                  'orders.php',             '🧾', 'vendas',    false],
    ['promocoes',            'Promoções',            'Criação e gestão de campanhas promocionais.',               'promotions.php',         '🏷️','marketing', false],
    ['kpis',                 'KPIs',                 'Indicadores de performance e metas.',                       'kpis.php',               '📈', 'analytics', false],
    ['analytics',            'Analytics',            'Análise de dados e relatórios avançados.',                  'analytics.php',          '📉', 'analytics', false],
    ['canais',               'Canais',               'Configuração de integrações (WhatsApp, Instagram).',        'integrations.php',       '🔗', 'config',    false],
    ['insights_ia',          'Insights IA',          'Sugestões e análises geradas por IA.',                     'insights.php',           '🤖', 'analytics', false],
    ['agenda',               'Agenda',               'Calendário geral de compromissos.',                         'calendar.php',           '📅', 'agenda',    false],
    ['agenda_barbearia',     'Agenda Barbearia',     'Agendamentos específicos para barbearia.',                  'calendar_barbearia.php', '✂️', 'agenda',    false],
    ['servicos_barbearia',   'Serviços Barbearia',   'Catálogo de serviços da barbearia.',                        'services_admin.php',     '💈', 'agenda',    false],
    ['equipe',               'Equipe',               'Gestão de funcionários e permissões.',                      'staff.php',              '👤', 'config',    false],
    ['configuracoes',        'Configurações',        'Configurações da empresa e do sistema.',                    'settings.php',           '⚙️', 'config',    true ],
];

/* ── Módulos ativos — SEMPRE um array válido ── */
$activeModules = [];
$modulesJson   = $company['modules_config'] ?? null;
if ($modulesJson) {
    $decoded = json_decode($modulesJson, true);
    if (is_array($decoded) && !empty($decoded)) {
        $activeModules = $decoded;
    }
}
// Primeira vez (nunca salvo) → todos ativos por padrão
if (empty($activeModules)) {
    foreach ($ALL_MODULES as $m) $activeModules[$m[0]] = true;
}

/* ── POST: empresa ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $nomeFantasia = trim($_POST['nome_fantasia'] ?? '');
    if ($nomeFantasia === '') { flash('error','Informe o nome fantasia.'); redirect('settings.php?tab=empresa'); }

    $logoPath    = $company['logo']    ?? null;
    $faviconPath = $company['favicon'] ?? null;

    foreach (['logo','favicon'] as $field) {
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
        ->execute([
            trim($_POST['nome_fantasia'] ?? ''),
            trim($_POST['razao_social']  ?? ''),
            trim($_POST['whatsapp_principal'] ?? ''),
            trim($_POST['instagram_usuario']  ?? ''),
            trim($_POST['email'] ?? ''),
            $logoPath, $faviconPath, $companyId
        ]);
    $_SESSION['company_name'] = trim($_POST['nome_fantasia']);
    flash('success','Configurações salvas.');
    redirect('settings.php?tab=empresa');
}

/* ── POST: módulos ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_modules'])) {
    $newModules = [];
    foreach ($ALL_MODULES as $m) {
        $newModules[$m[0]] = ($m[6] === true) ? true : isset($_POST['mod_'.$m[0]]);
    }
    $pdo->prepare('UPDATE companies SET modules_config=?,updated_at=NOW() WHERE id=?')
        ->execute([json_encode($newModules), $companyId]);
    $_SESSION['modules_config'] = $newModules;
    flash('success','Módulos atualizados. Menu atualizado imediatamente.');
    redirect('settings.php?tab=modulos');
}

$flashSuccess = get_flash('success');
$flashError   = get_flash('error');
$activeTab    = $_GET['tab'] ?? 'empresa';

/* ── Grupos ── */
$groups = [
    'core'      => ['label'=>'Core',              'icon'=>'⭐', 'desc'=>'Funcionalidades essenciais'],
    'vendas'    => ['label'=>'Vendas & Estoque',  'icon'=>'🛍️','desc'=>'PDV, produtos, pedidos e funil'],
    'marketing' => ['label'=>'Marketing',         'icon'=>'📣', 'desc'=>'Promoções e campanhas'],
    'analytics' => ['label'=>'Analytics & IA',   'icon'=>'📊', 'desc'=>'Relatórios, KPIs e inteligência artificial'],
    'agenda'    => ['label'=>'Agenda',            'icon'=>'📅', 'desc'=>'Calendários e agendamentos'],
    'config'    => ['label'=>'Configurações',     'icon'=>'⚙️', 'desc'=>'Canais, equipe e sistema'],
];

// Conta por grupo
$groupCounts = [];
foreach ($ALL_MODULES as $m) {
    $g = $m[5];
    if (!isset($groupCounts[$g])) $groupCounts[$g] = ['total'=>0,'active'=>0];
    $groupCounts[$g]['total']++;
    if (!empty($activeModules[$m[0]])) $groupCounts[$g]['active']++;
}

// Stats gerais — calculadas AQUI antes do HTML (nunca null)
$totalMods = count($ALL_MODULES);
$activeCnt = count(array_filter($activeModules, fn($v) => (bool)$v));

include __DIR__ . '/views/partials/header.php';
?>
<style>
/* ── Settings UI ── */
.set-wrap { max-width: 980px; }

/* Tabs */
.set-tabs { display:flex; gap:.25rem; border-bottom:2px solid #e2e8f0; margin-bottom:1.5rem; }
.set-tab  { padding:.65rem 1.25rem; font-size:.85rem; font-weight:600; color:#64748b; cursor:pointer;
            text-decoration:none; border-bottom:3px solid transparent; margin-bottom:-2px;
            transition:all .15s; border-radius:6px 6px 0 0; white-space:nowrap; }
.set-tab:hover  { color:#6366f1; background:#f5f3ff; }
.set-tab.active { color:#6366f1; border-bottom-color:#6366f1; background:#f5f3ff; }

/* Cards */
.set-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.set-card-header { padding:.85rem 1.25rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; }
.set-card-header h2 { font-size:.9rem; font-weight:700; color:#0f172a; }
.set-card-header p  { font-size:.75rem; color:#94a3b8; margin-top:.1rem; }
.set-card-body { padding:1.5rem; }

/* Flash */
.flash-ok  { padding:.75rem 1rem; border-radius:9px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.82rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:.5rem; }
.flash-err { padding:.75rem 1rem; border-radius:9px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.82rem; margin-bottom:1.25rem; }

/* Fields */
.field       { margin-bottom:1.1rem; }
.field:last-child { margin-bottom:0; }
.field label { display:block; font-size:.7rem; font-weight:700; text-transform:uppercase;
               letter-spacing:.06em; color:#64748b; margin-bottom:.35rem; }
.fi { width:100%; padding:.6rem .9rem; border:1.5px solid #e2e8f0; border-radius:9px;
      font-size:.875rem; background:#f8fafc; color:#0f172a; outline:none;
      transition:border-color .15s,background .15s; font-family:inherit; }
.fi:focus { border-color:#6366f1; background:#fff; box-shadow:0 0 0 3px rgba(99,102,241,.08); }
.fi-row2 { display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
.fi-row3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:.85rem; }

/* Empresa layout */
.empresa-grid { display:grid; grid-template-columns:1fr 300px; gap:1.25rem; align-items:start; }

/* Logo upload */
.logo-upload-box { border:1.5px dashed #e2e8f0; border-radius:12px; padding:1.1rem 1.25rem;
                   background:#f8fafc; transition:border-color .2s,background .2s; }
.logo-upload-box:hover { border-color:#6366f1; background:#f5f3ff; }
.logo-row { display:flex; align-items:center; gap:1rem; }
.logo-thumb { width:60px; height:60px; border-radius:10px; background:#e2e8f0; overflow:hidden;
              display:flex; align-items:center; justify-content:center; flex-shrink:0;
              border:1px solid #e2e8f0; }
.logo-thumb img { width:100%; height:100%; object-fit:cover; }
.logo-upload-info { flex:1; }
.logo-upload-info p { font-size:.75rem; font-weight:600; color:#374151; margin-bottom:.2rem; }
.logo-upload-info span { font-size:.68rem; color:#94a3b8; }
.logo-upload-info input { margin-top:.4rem; font-size:.75rem; color:#64748b; }

/* Btn */
.btn-save   { padding:.7rem 1.5rem; background:#6366f1; color:#fff; border:none; border-radius:9px;
              font-size:.875rem; font-weight:700; cursor:pointer; transition:background .15s; width:100%; }
.btn-save:hover { background:#4f46e5; }
.btn-green  { background:#16a34a; }
.btn-green:hover { background:#15803d; }

/* Módulos */
.mod-stats-bar { display:flex; gap:1rem; flex-wrap:wrap; padding:1rem 1.25rem;
                 background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:1.25rem; }
.mod-stat-item { text-align:center; min-width:70px; }
.mod-stat-num  { font-size:1.5rem; font-weight:800; line-height:1; }
.mod-stat-lbl  { font-size:.62rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-top:.15rem; }

.mod-grid { display:grid; grid-template-columns:1fr 210px; gap:1.25rem; align-items:start; }

.mod-group { margin-bottom:1.1rem; }
.mod-group-hd { display:flex; align-items:center; gap:.6rem; padding:.6rem .9rem;
                background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px 10px 0 0; border-bottom:none; }
.mod-group-hd-icon  { font-size:.95rem; }
.mod-group-hd-label { font-size:.8rem; font-weight:700; color:#0f172a; }
.mod-group-hd-desc  { font-size:.7rem; color:#94a3b8; }
.mod-group-hd-cnt   { margin-left:auto; font-size:.7rem; font-weight:700; color:#6366f1;
                      background:#ede9fe; padding:.15rem .5rem; border-radius:20px; }

.mod-list { border:1px solid #e2e8f0; border-radius:0 0 10px 10px; overflow:hidden; }
.mod-item { display:flex; align-items:center; gap:1rem; padding:.8rem 1rem;
            border-bottom:1px solid #f1f5f9; transition:background .1s; }
.mod-item:last-child { border-bottom:none; }
.mod-item:hover { background:#fafafe; }

.mod-icon { font-size:1rem; width:28px; text-align:center; flex-shrink:0; }
.mod-info { flex:1; min-width:0; }
.mod-name { font-size:.85rem; font-weight:600; color:#0f172a; }
.mod-desc { font-size:.7rem; color:#94a3b8; margin-top:.1rem; }
.always-badge { font-size:.62rem; font-weight:700; padding:.15rem .45rem; border-radius:20px;
                background:#f1f5f9; color:#94a3b8; white-space:nowrap; flex-shrink:0; }

/* Toggle */
.tog { position:relative; width:44px; height:24px; flex-shrink:0; }
.tog input { opacity:0; width:0; height:0; position:absolute; }
.tog-sl { position:absolute; inset:0; background:#e2e8f0; border-radius:24px; cursor:pointer; transition:.2s; }
.tog-sl::after { content:''; position:absolute; width:18px; height:18px; left:3px; top:3px;
                 background:#fff; border-radius:50%; transition:.2s; box-shadow:0 1px 3px rgba(0,0,0,.2); }
.tog input:checked + .tog-sl { background:#22c55e; }
.tog input:checked + .tog-sl::after { left:23px; }

/* Sidebar preview */
.sp-box { background:#1e293b; border-radius:12px; padding:.75rem; }
.sp-lbl { font-size:.58rem; text-transform:uppercase; letter-spacing:.08em; color:#475569; padding:.25rem .5rem; }
.sp-item { display:flex; align-items:center; gap:.5rem; padding:.35rem .6rem; border-radius:6px; font-size:.72rem; }
.sp-item.on  { color:#cbd5e1; }
.sp-item.off { opacity:.25; text-decoration:line-through; color:#64748b; }

/* Acesso */
.access-card { border:1.5px solid #e2e8f0; border-radius:12px; padding:1.25rem; }
.access-card.admin { border-color:#c7d2fe; background:#f5f3ff; }
</style>

<?php if ($flashSuccess): ?>
  <div class="flash-ok">✅ <?= sanitize($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="flash-err">⚠️ <?= sanitize($flashError) ?></div>
<?php endif; ?>

<div class="set-wrap">

  <div class="set-tabs">
    <a href="?tab=empresa"  class="set-tab <?= $activeTab==='empresa' ?'active':'' ?>">🏢 Empresa</a>
    <a href="?tab=modulos"  class="set-tab <?= $activeTab==='modulos'?'active':'' ?>">🧩 Módulos do Menu</a>
    <a href="?tab=acesso"   class="set-tab <?= $activeTab==='acesso'  ?'active':'' ?>">🔐 Acesso & Segurança</a>
  </div>

  <?php /* ════ TAB EMPRESA ════ */ if ($activeTab === 'empresa'): ?>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="save_company" value="1">
    <div class="empresa-grid">

      <!-- Coluna esquerda -->
      <div>
        <div class="set-card">
          <div class="set-card-header">
            <h2>🏢 Identidade da Empresa</h2>
            <p>Dados exibidos no painel, na loja pública e nos atendimentos.</p>
          </div>
          <div class="set-card-body">

            <div class="field">
              <label>Nome Fantasia *</label>
              <input class="fi" type="text" name="nome_fantasia"
                     value="<?= sanitize($company['nome_fantasia']??'') ?>" required
                     placeholder="Ex: For Men Store">
            </div>

            <div class="field">
              <label>Razão Social</label>
              <input class="fi" type="text" name="razao_social"
                     value="<?= sanitize($company['razao_social']??'') ?>"
                     placeholder="Ex: MenForST LTDA">
            </div>

            <div class="field fi-row2">
              <div>
                <label>WhatsApp Principal</label>
                <input class="fi" type="text" name="whatsapp_principal"
                       value="<?= sanitize($company['whatsapp_principal']??'') ?>"
                       placeholder="5565999999999">
              </div>
              <div>
                <label>Instagram</label>
                <input class="fi" type="text" name="instagram_usuario"
                       value="<?= sanitize($company['instagram_usuario']??'') ?>"
                       placeholder="@sualoja">
              </div>
            </div>

            <div class="field">
              <label>E-mail</label>
              <input class="fi" type="email" name="email"
                     value="<?= sanitize($company['email']??'') ?>"
                     placeholder="contato@empresa.com.br">
            </div>

          </div>
        </div>
      </div>

      <!-- Coluna direita -->
      <div style="display:flex;flex-direction:column;gap:1rem;">

        <div class="set-card">
          <div class="set-card-header"><h2>🖼️ Identidade Visual</h2></div>
          <div class="set-card-body" style="display:flex;flex-direction:column;gap:1rem;">

            <!-- Logo -->
            <div class="logo-upload-box">
              <p style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.65rem;">Logo da Empresa</p>
              <div class="logo-row">
                <div class="logo-thumb">
                  <?php if(!empty($company['logo'])): ?>
                    <img src="<?= sanitize($company['logo']) ?>" alt="Logo">
                  <?php else: ?>
                    <span style="font-size:1.4rem;">🏢</span>
                  <?php endif; ?>
                </div>
                <div class="logo-upload-info">
                  <p><?= empty($company['logo']) ? 'Nenhum logo enviado' : 'Logo atual' ?></p>
                  <span>JPG, PNG ou WEBP</span>
                  <input type="file" name="logo" accept="image/*">
                </div>
              </div>
            </div>

            <!-- Favicon -->
            <div class="logo-upload-box">
              <p style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.65rem;">Favicon <span style="font-weight:400;color:#94a3b8;">(ícone do browser)</span></p>
              <div class="logo-row">
                <div class="logo-thumb" style="width:40px;height:40px;border-radius:8px;">
                  <?php if(!empty($company['favicon'])): ?>
                    <img src="<?= sanitize($company['favicon']) ?>" alt="Favicon">
                  <?php else: ?>
                    <span style="font-size:1rem;">🔖</span>
                  <?php endif; ?>
                </div>
                <div class="logo-upload-info">
                  <p><?= empty($company['favicon']) ? 'Nenhum favicon' : 'Favicon atual' ?></p>
                  <span>ICO, PNG 32×32px</span>
                  <input type="file" name="favicon" accept="image/*">
                </div>
              </div>
            </div>

          </div>
        </div>

        <button type="submit" class="btn-save">💾 Salvar Configurações</button>
      </div>

    </div>
  </form>

  <?php /* ════ TAB MÓDULOS ════ */ elseif ($activeTab === 'modulos'): ?>

  <!-- Stats bar -->
  <div class="mod-stats-bar">
    <div class="mod-stat-item">
      <div class="mod-stat-num" style="color:#6366f1;"><?= $totalMods ?></div>
      <div class="mod-stat-lbl">Disponíveis</div>
    </div>
    <div class="mod-stat-item">
      <div class="mod-stat-num" style="color:#22c55e;"><?= $activeCnt ?></div>
      <div class="mod-stat-lbl">Ativos</div>
    </div>
    <div class="mod-stat-item">
      <div class="mod-stat-num" style="color:#94a3b8;"><?= $totalMods - $activeCnt ?></div>
      <div class="mod-stat-lbl">Ocultos</div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;">
      <p style="font-size:.75rem;color:#94a3b8;max-width:220px;text-align:right;">
        Desativar remove do menu. Os dados não são apagados — reative quando quiser.
      </p>
    </div>
  </div>

  <div class="mod-grid">

    <form method="POST" id="mod-form">
      <input type="hidden" name="save_modules" value="1">

      <?php
      $byGroup = [];
      foreach ($ALL_MODULES as $m) $byGroup[$m[5]][] = $m;
      foreach ($byGroup as $gKey => $mods):
        $g  = $groups[$gKey] ?? ['label'=>$gKey,'icon'=>'📌','desc'=>''];
        $gc = $groupCounts[$gKey] ?? ['active'=>0,'total'=>0];
      ?>
        <div class="mod-group">
          <div class="mod-group-hd">
            <span class="mod-group-hd-icon"><?= $g['icon'] ?></span>
            <span class="mod-group-hd-label"><?= $g['label'] ?></span>
            <span class="mod-group-hd-desc"> — <?= $g['desc'] ?></span>
            <span class="mod-group-hd-cnt"><?= $gc['active'] ?>/<?= $gc['total'] ?></span>
          </div>
          <div class="mod-list">
            <?php foreach ($mods as $m):
              [$key,$label,$desc,$file,$icon,$group,$alwaysOn] = $m;
              $isOn = !empty($activeModules[$key]);
            ?>
              <div class="mod-item">
                <span class="mod-icon"><?= $icon ?></span>
                <div class="mod-info">
                  <div class="mod-name"><?= $label ?></div>
                  <div class="mod-desc"><?= $desc ?></div>
                </div>
                <?php if ($alwaysOn): ?>
                  <span class="always-badge">Sempre ativo</span>
                  <input type="hidden" name="mod_<?= $key ?>" value="1">
                <?php else: ?>
                  <label class="tog">
                    <input type="checkbox" name="mod_<?= $key ?>" value="1"
                           <?= $isOn ? 'checked' : '' ?> onchange="updatePreview()">
                    <span class="tog-sl"></span>
                  </label>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div style="display:flex;gap:.6rem;margin-top:.5rem;flex-wrap:wrap;">
        <button type="button" onclick="toggleAll(true)"
                style="padding:.5rem 1rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;transition:all .15s;"
                onmouseover="this.style.borderColor='#22c55e'" onmouseout="this.style.borderColor='#e2e8f0'">
          ✅ Ativar todos
        </button>
        <button type="button" onclick="toggleAll(false)"
                style="padding:.5rem 1rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;cursor:pointer;color:#475569;transition:all .15s;"
                onmouseover="this.style.borderColor='#94a3b8'" onmouseout="this.style.borderColor='#e2e8f0'">
          ⬜ Desativar opcionais
        </button>
        <button type="submit" class="btn-save btn-green" style="margin-left:auto;width:auto;padding:.5rem 1.5rem;">
          💾 Salvar Módulos
        </button>
      </div>
    </form>

    <!-- Preview ao vivo -->
    <div style="position:sticky;top:1rem;">
      <p style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.5rem;">
        👁 Preview do Menu
      </p>
      <div class="sp-box" id="sp-box"></div>
      <p style="font-size:.62rem;color:#94a3b8;margin-top:.4rem;text-align:center;">Atualiza em tempo real</p>
    </div>

  </div>

  <?php /* ════ TAB ACESSO ════ */ elseif ($activeTab === 'acesso'): ?>

  <div class="set-card">
    <div class="set-card-header">
      <h2>🔐 Perfis de Acesso</h2>
      <p>Como o sistema diferencia Admin de Usuário comum.</p>
    </div>
    <div class="set-card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">
        <div class="access-card admin">
          <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
            <span style="font-size:1.2rem;">👑</span>
            <div>
              <p style="font-size:.875rem;font-weight:700;color:#4338ca;">Admin</p>
              <p style="font-size:.68rem;color:#6366f1;">Acesso total ao sistema</p>
            </div>
          </div>
          <ul style="font-size:.78rem;color:#374151;list-style:disc;padding-left:1rem;line-height:1.9;">
            <li>Todos os módulos ativos</li>
            <li>Configurações e módulos</li>
            <li>Relatórios e analytics</li>
            <li>Gestão de equipe</li>
            <li>Pedidos e financeiro</li>
            <li>Caixa (PDV)</li>
          </ul>
        </div>
        <div class="access-card">
          <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem;">
            <span style="font-size:1.2rem;">👤</span>
            <div>
              <p style="font-size:.875rem;font-weight:700;color:#374151;">Usuário</p>
              <p style="font-size:.68rem;color:#64748b;">Acesso operacional</p>
            </div>
          </div>
          <ul style="font-size:.78rem;color:#374151;list-style:disc;padding-left:1rem;line-height:1.9;">
            <li>Dashboard (leitura)</li>
            <li>Atendimento ao cliente</li>
            <li>Agenda e agendamentos</li>
            <li>Consulta de clientes</li>
            <li>Caixa (PDV)</li>
            <li style="color:#94a3b8;">❌ Sem configurações</li>
            <li style="color:#94a3b8;">❌ Sem analytics/KPIs</li>
          </ul>
        </div>
      </div>
      <div style="padding:1rem;border-radius:10px;background:#fef9c3;border:1px solid #fde68a;">
        <p style="font-size:.82rem;color:#92400e;font-weight:600;">💡 Gerenciar usuários</p>
        <p style="font-size:.75rem;color:#92400e;margin-top:.25rem;">
          Acesse <strong>Equipe</strong> para cadastrar funcionários e definir o nível de acesso (<code style="background:#fef3c7;padding:.1rem .3rem;border-radius:3px;">is_admin</code>).
        </p>
        <a href="staff.php" style="display:inline-block;margin-top:.6rem;padding:.4rem .85rem;background:#d97706;color:#fff;border-radius:6px;font-size:.75rem;font-weight:700;text-decoration:none;">
          → Gerenciar Equipe
        </a>
      </div>
    </div>
  </div>

  <div class="set-card">
    <div class="set-card-header"><h2>🔍 Sessão Atual</h2></div>
    <div class="set-card-body">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;">
        <?php foreach([
          ['Usuário', $_SESSION['user_name']  ?? '—'],
          ['Perfil',  !empty($_SESSION['is_admin']) ? '👑 Admin' : '👤 Usuário'],
          ['Empresa', $_SESSION['company_name'] ?? '—'],
        ] as [$l,$v]): ?>
          <div style="padding:.85rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;">
            <p style="font-size:.62rem;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;"><?= $l ?></p>
            <p style="font-size:.9rem;font-weight:700;color:#0f172a;margin-top:.3rem;"><?= sanitize($v) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php endif; ?>

</div>

<script>
const MOD_DATA = <?= json_encode(array_map(fn($m) => ['key'=>$m[0],'label'=>$m[1],'icon'=>$m[4],'always'=>$m[6]], $ALL_MODULES)) ?>;

function getActive() {
    const active = new Set();
    document.querySelectorAll('#mod-form input[type=checkbox]:checked').forEach(c => active.add(c.name.replace('mod_','')));
    document.querySelectorAll('#mod-form input[type=hidden][name^=mod_]').forEach(h => active.add(h.name.replace('mod_','')));
    return active;
}

function updatePreview() {
    const sp = document.getElementById('sp-box');
    if (!sp) return;
    const active = getActive();
    let html = '<div class="sp-lbl">Menu</div>';
    MOD_DATA.forEach(m => {
        const on = active.has(m.key);
        html += `<div class="sp-item ${on?'on':'off'}">${m.icon} ${m.label}</div>`;
    });
    sp.innerHTML = html;
}

function toggleAll(state) {
    document.querySelectorAll('#mod-form input[type=checkbox]').forEach(c => c.checked = state);
    updatePreview();
}

document.addEventListener('DOMContentLoaded', updatePreview);
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>