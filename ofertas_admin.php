<?php
// ============================================================
// ofertas_admin.php — Painel CRM para gerenciar a aba OFERTAS
// Padrão idêntico ao dashboard.php (helpers, db, require_login,
// current_company_id, header/footer partials, Tailwind)
// ============================================================

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

if (!$companyId) {
    flash('error', 'Empresa não definida na sessão.');
    redirect('dashboard.php');
}

$msg  = '';
$erro = '';

// ── Helper: salva/atualiza uma chave em company_settings ──────
function upsert_setting(PDO $pdo, int $cid, string $key, string $value): void {
    $exists = $pdo->prepare("SELECT id FROM company_settings WHERE company_id=? AND setting_key=?")->execute([$cid,$key]);
    $row    = $pdo->prepare("SELECT id FROM company_settings WHERE company_id=? AND setting_key=?");
    $row->execute([$cid, $key]);
    if ($row->fetchColumn()) {
        $pdo->prepare("UPDATE company_settings SET setting_value=? WHERE company_id=? AND setting_key=?")
            ->execute([$value, $cid, $key]);
    } else {
        $pdo->prepare("INSERT INTO company_settings (company_id,setting_key,setting_value) VALUES (?,?,?)")
            ->execute([$cid, $key, $value]);
    }
}

// ── AÇÕES POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // ── Salvar configurações da promoção ──────────────────────
    if ($acao === 'salvar_config') {
        try {
            upsert_setting($pdo, $companyId, 'flash_titulo',        trim($_POST['flash_titulo']        ?? ''));
            upsert_setting($pdo, $companyId, 'flash_subtitulo',     trim($_POST['flash_subtitulo']     ?? ''));
            upsert_setting($pdo, $companyId, 'flash_validade',      trim($_POST['flash_validade']      ?? ''));
            upsert_setting($pdo, $companyId, 'flash_estoque_total', trim($_POST['flash_estoque_total'] ?? '200'));
            upsert_setting($pdo, $companyId, 'flash_unidade',       trim($_POST['flash_unidade']       ?? 'unidades'));
            upsert_setting($pdo, $companyId, 'flash_ativo',         isset($_POST['flash_ativo']) ? '1' : '0');

            // Chips fixos — monta JSON a partir dos campos
            $chips = [];
            $cNomes = $_POST['chip_nome'] ?? [];
            $cDes   = $_POST['chip_de']   ?? [];
            $cPors  = $_POST['chip_por']  ?? [];
            foreach ($cNomes as $i => $nome) {
                $nome = trim($nome);
                $por  = trim($cPors[$i] ?? '');
                if ($nome && $por) {
                    $chips[] = ['nome' => $nome, 'de' => trim($cDes[$i] ?? ''), 'por' => $por];
                }
            }
            upsert_setting($pdo, $companyId, 'flash_chips', json_encode($chips, JSON_UNESCAPED_UNICODE));
            flash('success', 'Configurações salvas!');
        } catch (\Exception $e) {
            flash('error', 'Erro ao salvar: ' . $e->getMessage());
        }
        redirect('ofertas_admin.php');
    }

    // Ativar produto em oferta
    if ($acao === 'ativar') {
        $id             = (int)$_POST['product_id'];
        $precoOferta    = (float)str_replace(',', '.', $_POST['preco_oferta']  ?? '0');
        $precoOriginal  = (float)str_replace(',', '.', $_POST['preco_original'] ?? '0');
        $estoque        = (int)($_POST['oferta_estoque']  ?? 0);
        $validade       = trim($_POST['oferta_validade']  ?? '') ?: null;
        $parcelas       = (int)($_POST['oferta_parcelas'] ?? 2);

        if ($precoOferta <= 0) {
            $erro = 'Informe um preço promocional válido.';
        } else {
            $s = $pdo->prepare("
                UPDATE products SET
                    em_oferta       = 1,
                    preco_oferta    = :po,
                    preco_original  = :pori,
                    oferta_estoque  = :est,
                    oferta_validade = :val,
                    oferta_parcelas = :parc
                WHERE id = :id AND company_id = :cid
            ");
            $s->execute([
                ':po'   => $precoOferta,
                ':pori' => $precoOriginal ?: null,
                ':est'  => $estoque ?: null,
                ':val'  => $validade,
                ':parc' => $parcelas ?: 2,
                ':id'   => $id,
                ':cid'  => $companyId,
            ]);
            $msg = 'Produto adicionado à oferta!';
        }
    }

    // Remover da oferta
    if ($acao === 'remover') {
        $id = (int)$_POST['product_id'];
        $pdo->prepare("UPDATE products SET em_oferta=0 WHERE id=? AND company_id=?")
            ->execute([$id, $companyId]);
        $msg = 'Produto removido da oferta.';
    }

    // Atualizar estoque
    if ($acao === 'estoque') {
        $id  = (int)$_POST['product_id'];
        $est = (int)$_POST['novo_estoque'];
        $pdo->prepare("UPDATE products SET oferta_estoque=? WHERE id=? AND company_id=?")
            ->execute([$est, $id, $companyId]);
        $msg = 'Estoque atualizado.';
    }

    // Remover todos
    if ($acao === 'limpar_tudo') {
        $pdo->prepare("UPDATE products SET em_oferta=0 WHERE company_id=?")
            ->execute([$companyId]);
        $msg = 'Todos os produtos foram removidos da oferta.';
    }

    if ($msg)  flash('success', $msg);
    if ($erro) flash('error',   $erro);
    redirect('ofertas_admin.php');
}

// ── Recupera flash messages ────────────────────────────────────
$flashMsg  = get_flash('success');
$flashErro = get_flash('error');

// ── Busca / Filtro ─────────────────────────────────────────────
$search = trim($_GET['q']      ?? '');
$filtro = $_GET['filtro']      ?? 'todos'; // todos | em_oferta | fora

$where  = 'company_id = :cid AND ativo = 1';
$params = [':cid' => $companyId];

if ($search !== '') {
    $where   .= ' AND nome LIKE :q';
    $params[':q'] = '%' . $search . '%';
}
if ($filtro === 'em_oferta') { $where .= ' AND em_oferta = 1'; }
if ($filtro === 'fora')      { $where .= ' AND em_oferta = 0'; }

$stmt = $pdo->prepare("SELECT * FROM products WHERE $where ORDER BY em_oferta DESC, nome ASC");
$stmt->execute($params);
$produtos = $stmt->fetchAll();

// KPIs rápidos
$totalOferta   = (int)$pdo->prepare("SELECT COUNT(*) FROM products WHERE company_id=? AND em_oferta=1")->execute([$companyId]) ? $pdo->query("SELECT COUNT(*) FROM products WHERE company_id=$companyId AND em_oferta=1")->fetchColumn() : 0;
$totalEstoque  = (int)$pdo->query("SELECT COALESCE(SUM(oferta_estoque),0) FROM products WHERE company_id=$companyId AND em_oferta=1")->fetchColumn();

// ── Lê configurações da promoção ──────────────────────────────
$cfg = [];
try {
    $cs = $pdo->prepare("SELECT setting_key, setting_value FROM company_settings WHERE company_id=? AND setting_key LIKE 'flash_%'");
    $cs->execute([$companyId]);
    while ($r = $cs->fetch()) $cfg[$r['setting_key']] = $r['setting_value'];
} catch (\Exception $e) {}

$cfgTitulo    = $cfg['flash_titulo']         ?? 'Liquidação Inédita de Estoque';
$cfgSubtitulo = $cfg['flash_subtitulo']      ?? 'Peças 100% Originais · Até 60% OFF · Entrega Disponível';
$cfgValidade  = $cfg['flash_validade']       ?? date('Y-m-d', strtotime('next saturday')) . ' 23:59:59';
$cfgEstTotal  = $cfg['flash_estoque_total']  ?? '200';
$cfgUnidade   = $cfg['flash_unidade']        ?? 'unidades';
$cfgAtivo     = ($cfg['flash_ativo']         ?? '1') === '1';
$cfgValDt     = date('Y-m-d\TH:i', strtotime($cfgValidade));

// Chips fixos
$cfgChips = [['nome'=>'','de'=>'','por'=>''],['nome'=>'','de'=>'','por'=>''],['nome'=>'','de'=>'','por'=>''],['nome'=>'','de'=>'','por'=>'']];
if (!empty($cfg['flash_chips'])) {
    $dec = json_decode($cfg['flash_chips'], true);
    if (is_array($dec)) {
        foreach ($dec as $i => $c) { if ($i < 4) $cfgChips[$i] = $c; }
    }
}
$totalProdutos = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE company_id=$companyId AND ativo=1")->fetchColumn();

// Data padrão de validade: próximo sábado
$defaultValidade = date('Y-m-d\TH:i', strtotime('next saturday 23:59'));

include __DIR__ . '/views/partials/header.php';
?>

<style>
/* ── KPIs ── */
.kpi-grid  { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.85rem; margin-bottom:1.4rem; }
.kpi-card  { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1rem 1.25rem; position:relative; overflow:hidden; }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--ac,#6366f1); border-radius:14px 14px 0 0; }
.kpi-lbl   { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:.4rem; }
.kpi-val   { font-size:2rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-sub   { font-size:.67rem; color:#94a3b8; margin-top:.3rem; }

/* ── Topbar ── */
.page-hd { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem; }
.page-hd h1 { font-size:1.35rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:.5rem; }
.page-hd h1 span { color:#6366f1; }

/* ── Toolbar ── */
.toolbar { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
.search-box { background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; padding:8px 14px; border-radius:10px; font-size:14px; min-width:200px; flex:1; max-width:300px; }
.search-box:focus { outline:none; border-color:#6366f1; }
.tabs { display:flex; gap:.4rem; }
.tab { padding:7px 14px; border-radius:8px; font-size:12px; font-weight:700; text-decoration:none; border:1px solid #e2e8f0; color:#64748b; background:#fff; cursor:pointer; }
.tab:hover { border-color:#6366f1; color:#6366f1; }
.tab.active { background:#6366f1; border-color:#6366f1; color:#fff; }
.btn-clear { margin-left:auto; background:#fff; border:1px solid #fca5a5; color:#ef4444; padding:7px 14px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
.btn-clear:hover { background:#ef4444; color:#fff; }

/* ── View link ── */
.view-link { display:inline-flex; align-items:center; gap:.3rem; font-size:.8rem; color:#16a34a; font-weight:600; text-decoration:none; border:1px solid #bbf7d0; padding:6px 14px; border-radius:8px; background:#f0fdf4; }
.view-link:hover { background:#dcfce7; }

/* ── Alerts ── */
.alert { padding:.75rem 1rem; border-radius:10px; font-size:.82rem; font-weight:600; margin-bottom:1rem; }
.alert-ok  { background:#dcfce7; border:1px solid #86efac; color:#15803d; }
.alert-err { background:#fee2e2; border:1px solid #fca5a5; color:#dc2626; }

/* ── Table ── */
.dp { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.dp-hd { padding:.85rem 1.25rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.dp-title { font-size:.9rem; font-weight:700; color:#0f172a; }
table { width:100%; border-collapse:collapse; }
th { text-align:left; padding:9px 14px; font-size:.65rem; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; border-bottom:1px solid #f1f5f9; white-space:nowrap; }
td { padding:11px 14px; border-bottom:1px solid #f8fafc; vertical-align:middle; }
tr:hover td { background:#fafbfc; }
tr:last-child td { border-bottom:none; }

/* ── Badges ── */
.badge-on  { background:#dcfce7; color:#15803d; border:1px solid #86efac;  padding:2px 10px; border-radius:20px; font-size:.65rem; font-weight:700; }
.badge-off { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;  padding:2px 10px; border-radius:20px; font-size:.65rem; font-weight:700; }

/* ── Prod thumb ── */
.pthumb { width:40px; height:40px; object-fit:cover; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; }
.pthumb-ph { width:40px; height:40px; background:#f1f5f9; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; border:1px solid #e2e8f0; }

/* ── Price fields ── */
.preco-promo { font-weight:700; color:#6366f1; }
.preco-orig  { font-size:.72rem; color:#94a3b8; text-decoration:line-through; }

/* ── Inline form ── */
.irow  { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
.iinp  { background:#f8fafc; border:1px solid #e2e8f0; color:#0f172a; padding:5px 9px; border-radius:7px; font-size:.8rem; width:100px; }
.iinp:focus { outline:none; border-color:#6366f1; }
.iinp.sm   { width:64px; }
.iinp.dt   { width:150px; }
.iinp.parc { width:64px; }
.ibtn  { padding:6px 13px; border-radius:7px; font-size:.78rem; font-weight:700; cursor:pointer; border:none; transition:all .12s; }
.ibtn-add  { background:#6366f1; color:#fff; }
.ibtn-add:hover  { background:#4f46e5; }
.ibtn-upd  { background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }
.ibtn-upd:hover  { background:#bae6fd; }
.ibtn-rem  { background:#fff; color:#ef4444; border:1px solid #fca5a5; }
.ibtn-rem:hover  { background:#ef4444; color:#fff; }

/* ── Config panel ── */
.cfg-panel { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.cfg-hd { padding:.85rem 1.25rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; cursor:pointer; user-select:none; }
.cfg-hd-left { display:flex; align-items:center; gap:.5rem; }
.cfg-hd h3 { font-size:.88rem; font-weight:700; color:#0f172a; }
.cfg-hd .toggle-ico { font-size:.8rem; color:#94a3b8; transition:transform .2s; }
.cfg-body { padding:1.1rem 1.25rem; display:none; }
.cfg-body.open { display:block; }
.cfg-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.cfg-grid-1 { grid-template-columns:1fr; }
.cfg-label { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#64748b; display:block; margin-bottom:.3rem; }
.cfg-inp { width:100%; padding:.5rem .75rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.84rem; color:#0f172a; background:#f8fafc; font-family:inherit; }
.cfg-inp:focus { outline:none; border-color:#6366f1; background:#fff; }
.cfg-chip-row { display:grid; grid-template-columns:2fr 1fr 1fr; gap:.5rem; align-items:end; }
.cfg-save { background:#6366f1; color:#fff; border:none; border-radius:8px; padding:.6rem 1.4rem; font-size:.84rem; font-weight:700; cursor:pointer; }
.cfg-save:hover { background:#4f46e5; }
.cfg-note { font-size:.7rem; color:#94a3b8; margin-top:.35rem; }
.tog2 { position:relative; width:40px; height:22px; flex-shrink:0; }
.tog2 input { opacity:0; width:0; height:0; }
.tog2-sl { position:absolute; inset:0; background:#e2e8f0; border-radius:22px; cursor:pointer; transition:.2s; }
.tog2-sl::after { content:''; position:absolute; width:16px; height:16px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; }
.tog2 input:checked + .tog2-sl { background:#22c55e; }
.tog2 input:checked + .tog2-sl::after { left:21px; }
@media (max-width:768px) {
  .cfg-grid { grid-template-columns:1fr; }
  .cfg-chip-row { grid-template-columns:1fr 1fr; }
  .cfg-chip-row .cfg-inp:first-child { grid-column:1/-1; }
}
</style>

<!-- Topbar -->
<div class="page-hd">
    <h1>⚡ Gerenciar <span>Ofertas</span></h1>
    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/ofertas.php?empresa=<?= urlencode($_SESSION['company_slug'] ?? '') ?>"
           target="_blank" rel="noopener" class="view-link">
            👁 Ver página pública ↗
        </a>
        <a href="<?= BASE_URL ?>/dashboard.php" class="tab">← Dashboard</a>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card" style="--ac:#6366f1;">
        <p class="kpi-lbl">Em oferta</p>
        <p class="kpi-val"><?= $totalOferta ?></p>
        <p class="kpi-sub">produto(s) ativos</p>
    </div>
    <div class="kpi-card" style="--ac:#22c55e;">
        <p class="kpi-lbl">Estoque total</p>
        <p class="kpi-val"><?= $totalEstoque ?: '—' ?></p>
        <p class="kpi-sub">pares disponíveis</p>
    </div>
    <div class="kpi-card" style="--ac:#0ea5e9;">
        <p class="kpi-lbl">Catálogo ativo</p>
        <p class="kpi-val"><?= $totalProdutos ?></p>
        <p class="kpi-sub">produtos na loja</p>
    </div>
</div>

<!-- Alerts -->
<?php if ($flashMsg):  ?><div class="alert alert-ok">✅ <?= sanitize($flashMsg) ?></div><?php endif; ?>
<?php if ($flashErro): ?><div class="alert alert-err">⚠️ <?= sanitize($flashErro) ?></div><?php endif; ?>

<!-- ══ PAINEL DE CONFIGURAÇÃO DA PROMOÇÃO ══ -->
<div class="cfg-panel">
    <div class="cfg-hd" onclick="toggleCfg()">
        <div class="cfg-hd-left">
            <span style="font-size:1rem;">⚙️</span>
            <h3>Configurações da Promoção</h3>
            <span style="font-size:.72rem;color:#94a3b8;margin-left:.5rem;">título, unidade, chips do hero, validade</span>
        </div>
        <span class="toggle-ico" id="cfg-ico">▼</span>
    </div>
    <div class="cfg-body" id="cfg-body">
        <form method="POST">
            <input type="hidden" name="acao" value="salvar_config">

            <div class="cfg-grid" style="margin-bottom:.85rem;">
                <!-- Título -->
                <div>
                    <label class="cfg-label">Título da promoção</label>
                    <input type="text" name="flash_titulo" class="cfg-inp"
                           value="<?= htmlspecialchars($cfgTitulo) ?>" placeholder="Liquidação Inédita de Estoque">
                </div>
                <!-- Subtítulo -->
                <div>
                    <label class="cfg-label">Subtítulo / descritivo</label>
                    <input type="text" name="flash_subtitulo" class="cfg-inp"
                           value="<?= htmlspecialchars($cfgSubtitulo) ?>" placeholder="Peças 100% Originais · Até 60% OFF">
                </div>
                <!-- Validade -->
                <div>
                    <label class="cfg-label">Data/hora de encerramento</label>
                    <input type="datetime-local" name="flash_validade" class="cfg-inp"
                           value="<?= $cfgValDt ?>">
                </div>
                <!-- Estoque total + Unidade -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                    <div>
                        <label class="cfg-label">Estoque total</label>
                        <input type="number" name="flash_estoque_total" class="cfg-inp"
                               value="<?= htmlspecialchars($cfgEstTotal) ?>" min="1">
                    </div>
                    <div>
                        <label class="cfg-label">Unidade</label>
                        <select name="flash_unidade" class="cfg-inp">
                            <?php foreach (['unidades','pares','peças','itens','kits'] as $u): ?>
                            <option value="<?= $u ?>" <?= $cfgUnidade===$u?'selected':'' ?>><?= $u ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <!-- Ativo -->
                <div style="display:flex;align-items:center;gap:.6rem;margin-top:.25rem;">
                    <label class="tog2">
                        <input type="checkbox" name="flash_ativo" value="1" <?= $cfgAtivo?'checked':'' ?>>
                        <span class="tog2-sl"></span>
                    </label>
                    <span style="font-size:.82rem;color:#374151;font-weight:500;">Promoção ativa (visível na página pública)</span>
                </div>
            </div>

            <!-- Chips do Hero -->
            <div style="margin-bottom:.85rem;">
                <label class="cfg-label" style="margin-bottom:.5rem;">
                    Chips de destaque no hero
                    <span style="text-transform:none;letter-spacing:0;font-weight:400;color:#94a3b8;">
                        — se vazio, usa os 4 primeiros produtos em oferta automaticamente
                    </span>
                </label>
                <?php foreach ($cfgChips as $i => $chip): ?>
                <div class="cfg-chip-row" style="margin-bottom:.4rem;">
                    <div>
                        <?php if ($i === 0): ?><label class="cfg-label" style="font-size:.62rem;">Nome do produto/categoria</label><?php endif; ?>
                        <input type="text" name="chip_nome[]" class="cfg-inp"
                               value="<?= htmlspecialchars($chip['nome'] ?? '') ?>" placeholder="Ex: Camiseta Básica">
                    </div>
                    <div>
                        <?php if ($i === 0): ?><label class="cfg-label" style="font-size:.62rem;">Preço original (DE)</label><?php endif; ?>
                        <input type="text" name="chip_de[]" class="cfg-inp"
                               value="<?= htmlspecialchars($chip['de'] ?? '') ?>" placeholder="R$89,90">
                    </div>
                    <div>
                        <?php if ($i === 0): ?><label class="cfg-label" style="font-size:.62rem;">Preço oferta (POR)</label><?php endif; ?>
                        <input type="text" name="chip_por[]" class="cfg-inp"
                               value="<?= htmlspecialchars($chip['por'] ?? '') ?>" placeholder="R$39,90">
                    </div>
                </div>
                <?php endforeach; ?>
                <p class="cfg-note">Deixe Nome e POR em branco para usar modo dinâmico (produtos cadastrados).</p>
            </div>

            <button type="submit" class="cfg-save">💾 Salvar configurações</button>
        </form>
    </div>
</div>

<!-- Toolbar -->
<form method="GET" class="toolbar">
    <input type="text" name="q" class="search-box" placeholder="🔍 Buscar produto…" value="<?= sanitize($search) ?>" autocomplete="off">
    <input type="hidden" name="filtro" value="<?= $filtro ?>">

    <div class="tabs">
        <a href="?filtro=todos&q=<?= urlencode($search) ?>"     class="tab <?= $filtro==='todos'     ?'active':'' ?>">Todos</a>
        <a href="?filtro=em_oferta&q=<?= urlencode($search) ?>" class="tab <?= $filtro==='em_oferta' ?'active':'' ?>">Em Oferta</a>
        <a href="?filtro=fora&q=<?= urlencode($search) ?>"      class="tab <?= $filtro==='fora'      ?'active':'' ?>">Fora</a>
    </div>

    <!-- Limpar todos (form separado dentro do label para não conflitar) -->
    <span style="margin-left:auto;">
        <button type="button" onclick="limparTudo()" class="btn-clear">🧹 Remover todos da oferta</button>
    </span>
</form>

<!-- Tabela -->
<div class="dp">
    <div class="dp-hd">
        <span class="dp-title">Produtos (<?= count($produtos) ?> exibidos)</span>
        <span style="font-size:.72rem;color:#94a3b8;">Preencha os campos e clique + para ativar</span>
    </div>

    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Status</th>
                    <th>Preço loja</th>
                    <th>Preço oferta</th>
                    <th>Estoque</th>
                    <th>Validade</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($produtos)): ?>
                <tr class="empty-row"><td colspan="7">Nenhum produto encontrado.</td></tr>
            <?php endif; ?>

            <?php foreach ($produtos as $p):
                $pPreco = (float)($p['preco'] ?? 0);
            ?>
            <tr>
                <!-- Produto -->
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if (!empty($p['imagem'])): ?>
                            <img src="<?= sanitize(image_url($p['imagem'])) ?>" class="pthumb" alt="">
                        <?php else: ?>
                            <div class="pthumb-ph">👟</div>
                        <?php endif; ?>
                        <div>
                            <div style="font-size:.82rem;font-weight:700;color:#0f172a;"><?= sanitize($p['nome']) ?></div>
                            <div style="font-size:.7rem;color:#94a3b8;"><?= sanitize($p['categoria'] ?? '') ?></div>
                        </div>
                    </div>
                </td>

                <!-- Status -->
                <td>
                    <?php if ($p['em_oferta']): ?>
                        <span class="badge-on">● EM OFERTA</span>
                    <?php else: ?>
                        <span class="badge-off">○ Inativo</span>
                    <?php endif; ?>
                </td>

                <!-- Preço loja -->
                <td style="font-size:.82rem;color:#475569;"><?= format_currency($pPreco) ?></td>

                <!-- Preço oferta -->
                <td>
                    <?php if ($p['em_oferta'] && $p['preco_oferta']): ?>
                        <div class="preco-promo"><?= format_currency($p['preco_oferta']) ?></div>
                        <?php if ($p['preco_original']): ?>
                            <div class="preco-orig"><?= format_currency($p['preco_original']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>

                <!-- Estoque (editável inline se em oferta) -->
                <td>
                    <?php if ($p['em_oferta']): ?>
                        <form method="POST" class="irow">
                            <input type="hidden" name="acao" value="estoque">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                            <input type="number" name="novo_estoque" class="iinp sm"
                                   value="<?= (int)($p['oferta_estoque'] ?? 0) ?>" min="0">
                            <button type="submit" class="ibtn ibtn-upd">✓</button>
                        </form>
                    <?php else: ?>
                        <span style="color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>

                <!-- Validade -->
                <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap;">
                    <?= $p['oferta_validade'] ? date('d/m/Y H:i', strtotime($p['oferta_validade'])) : '—' ?>
                </td>

                <!-- Ação -->
                <td>
                    <?php if ($p['em_oferta']): ?>
                        <!-- Remover -->
                        <form method="POST">
                            <input type="hidden" name="acao" value="remover">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="ibtn ibtn-rem">✕ Remover</button>
                        </form>
                    <?php else: ?>
                        <!-- Formulário para ativar -->
                        <form method="POST" class="irow">
                            <input type="hidden" name="acao" value="ativar">
                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">

                            <input type="number" name="preco_oferta" class="iinp"
                                   step="0.01" placeholder="R$ promo" required>

                            <input type="number" name="preco_original" class="iinp"
                                   step="0.01" placeholder="R$ original"
                                   value="<?= $pPreco > 0 ? $pPreco : '' ?>">

                            <input type="number" name="oferta_estoque" class="iinp sm"
                                   placeholder="Estoque" min="0">

                            <select name="oferta_parcelas" class="iinp parc">
                                <option value="1">1x</option>
                                <option value="2" selected>2x</option>
                                <option value="3">3x</option>
                            </select>

                            <input type="datetime-local" name="oferta_validade" class="iinp dt"
                                   value="<?= $defaultValidade ?>">

                            <button type="submit" class="ibtn ibtn-add">+ Adicionar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Form oculto para limpar tudo -->
<form method="POST" id="form-limpar" style="display:none;">
    <input type="hidden" name="acao" value="limpar_tudo">
</form>

<script>
// Auto-submit na busca
const si = document.querySelector('.search-box');
let searchTimer;
si?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => si.closest('form').submit(), 500);
});

// Confirma limpar tudo
function limparTudo() {
    if (confirm('Remover TODOS os produtos da oferta desta empresa?')) {
        document.getElementById('form-limpar').submit();
    }
}

// Toggle painel config
function toggleCfg() {
    const body = document.getElementById('cfg-body');
    const ico  = document.getElementById('cfg-ico');
    const open = body.classList.toggle('open');
    ico.textContent = open ? '▲' : '▼';
}
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>