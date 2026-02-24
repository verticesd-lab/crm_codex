<?php
/**
 * products.php — Gestão de Produtos / Serviços
 * Layout: tabela densa + painel lateral de edição
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$companyId = current_company_id();
if (!$companyId) {
    $row = $pdo->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetch();
    if ($row) { $companyId = (int)$row['id']; $_SESSION['company_id'] = $companyId; }
    else { echo 'Empresa não configurada.'; exit; }
}

/* ─── helpers de variantes ─────────────────────────────────────── */
function sync_variants($pdo, int $pid, string $sizesCsv): void {
    $sizes = array_filter(array_map('trim', explode(',', $sizesCsv)));
    $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id=?');
    $stmt->execute([$pid]);
    $old = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($old) {
        $ph = implode(',', array_fill(0, count($old), '?'));
        $pdo->prepare("DELETE FROM stock_movements WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM stock_balances WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM product_variants WHERE id IN ($ph)")->execute($old);
    }
    foreach ($sizes as $sz) {
        $pdo->prepare('INSERT INTO product_variants (product_id,size,active,created_at,updated_at) VALUES (?,?,1,NOW(),NOW())')->execute([$pid,$sz]);
        $vid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO stock_balances (product_variant_id,location,quantity,updated_at) VALUES (?,?,0,NOW())')->execute([$vid,'loja_fisica']);
    }
}

function parse_price(string $v): float {
    $v = str_replace(['R$',' '], '', $v);
    if (substr_count($v,',') && substr_count($v,'.')) { $v = str_replace('.','',$v); $v = str_replace(',','.',$v); }
    else $v = str_replace(',','.',$v);
    return max(0, (float)preg_replace('/[^0-9.]/','',$v));
}

/* ─── state ─────────────────────────────────────────────────────── */
$action       = $_GET['action'] ?? 'list';
$q            = trim($_GET['q'] ?? '');
$cat          = trim($_GET['categoria'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));

// CORREÇÃO: Pegamos o valor com fallback para 30 ANTES de validar no in_array
$requestedPP  = (int)($_GET['per_page'] ?? 30);
$perPage      = in_array($requestedPP, [30, 60, 100]) ? $requestedPP : 30;

$filterAt     = $_GET['ativo'] ?? '';   // '1','0',''

$flashSuccess = get_flash('success');
$flashError   = get_flash('error');

// Mantemos todos os filtros na query string de retorno
$backQs = http_build_query([
    'q'         => $q,
    'categoria' => $cat,
    'page'      => $page,
    'per_page'  => $perPage,
    'ativo'     => $filterAt
]);

/* ─── DELETE ─────────────────────────────────────────────────────── */
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $old = $pdo->prepare('SELECT id FROM product_variants WHERE product_id=?');
    $old->execute([$id]); $old = $old->fetchAll(PDO::FETCH_COLUMN);
    if ($old) {
        $ph = implode(',', array_fill(0,count($old),'?'));
        $pdo->prepare("DELETE FROM stock_movements WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM stock_balances WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM product_variants WHERE id IN ($ph)")->execute($old);
    }
    $pdo->prepare('DELETE FROM products WHERE id=? AND company_id=?')->execute([$id,$companyId]);
    flash('success','Produto removido.');
    redirect('products.php'.($backQs?'?'.$backQs:''));
}

/* ─── TOGGLE ATIVO (AJAX-friendly) ──────────────────────────────── */
if ($action === 'toggle_ativo' && isset($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $cur = $pdo->prepare('SELECT ativo FROM products WHERE id=? AND company_id=?');
    $cur->execute([$id,$companyId]);
    $cur = $cur->fetchColumn();
    $pdo->prepare('UPDATE products SET ativo=?,updated_at=NOW() WHERE id=? AND company_id=?')
        ->execute([$cur ? 0 : 1, $id, $companyId]);
    flash('success', $cur ? 'Produto desativado.' : 'Produto ativado.');
    redirect('products.php'.($backQs?'?'.$backQs:''));
}

/* ─── TOGGLE DESTAQUE ────────────────────────────────────────────── */
if ($action === 'toggle_destaque' && isset($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $cur = $pdo->prepare('SELECT destaque FROM products WHERE id=? AND company_id=?');
    $cur->execute([$id,$companyId]);
    $cur = $cur->fetchColumn();
    $pdo->prepare('UPDATE products SET destaque=?,updated_at=NOW() WHERE id=? AND company_id=?')
        ->execute([$cur ? 0 : 1, $id, $companyId]);
    redirect('products.php'.($backQs?'?'.$backQs:''));
}

/* ─── CREATE / UPDATE ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = (int)($_POST['id'] ?? 0);
    $nome      = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $preco     = parse_price($_POST['preco'] ?? '0');
    $precoCusto= isset($_POST['preco_custo']) ? parse_price($_POST['preco_custo']) : null;
    $catPost   = trim($_POST['categoria'] ?? '');
    $ref       = trim($_POST['referencia'] ?? '');
    $cor       = trim($_POST['cor'] ?? '');
    $estoque   = max(0,(int)($_POST['estoque'] ?? 0));
    $desconto  = max(0,min(100,(float)($_POST['desconto'] ?? 0)));
    $ativo     = isset($_POST['ativo']) ? 1 : 0;
    $destaque  = isset($_POST['destaque']) ? 1 : 0;
    $sizes     = trim($_POST['sizes'] ?? '');
    $oldImage  = trim($_POST['current_image'] ?? '');

    $retQ = http_build_query(array_filter([
        'q'        => trim($_POST['return_q'] ?? ''),
        'categoria'=> trim($_POST['return_cat'] ?? ''),
        'page'     => max(1,(int)($_POST['return_page']??1)),
        'per_page' => $_POST['return_per_page'] ?? 30,
        'ativo'    => $_POST['return_ativo'] ?? '',
    ]));

    if ($nome === '' || $preco <= 0) {
        flash('error','Informe nome e preço válido.');
        redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ);
    }

    $imgPath = $oldImage;
    if (!empty($_FILES['imagem']['name']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $up = upload_image_optimized('imagem','uploads/products');
        if ($up === null) { flash('error','Falha no upload da imagem.'); redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ); }
        $imgPath = $up;
    }

    // Verifica colunas extras
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('referencia',$cols)) $pdo->exec("ALTER TABLE products ADD COLUMN referencia VARCHAR(100) DEFAULT NULL");
        if (!in_array('cor',$cols))        $pdo->exec("ALTER TABLE products ADD COLUMN cor VARCHAR(80) DEFAULT NULL");
        if (!in_array('estoque',$cols))    $pdo->exec("ALTER TABLE products ADD COLUMN estoque INT DEFAULT 0");
        if (!in_array('desconto',$cols))   $pdo->exec("ALTER TABLE products ADD COLUMN desconto DECIMAL(5,2) DEFAULT 0");
        if (!in_array('preco_custo',$cols))$pdo->exec("ALTER TABLE products ADD COLUMN preco_custo DECIMAL(10,2) DEFAULT NULL");
    } catch(Throwable $e) {}

    if ($id > 0) {
        $pdo->prepare('UPDATE products SET nome=?,descricao=?,preco=?,preco_custo=?,categoria=?,referencia=?,cor=?,estoque=?,desconto=?,sizes=?,imagem=?,ativo=?,destaque=?,updated_at=NOW() WHERE id=? AND company_id=?')
            ->execute([$nome,$descricao,$preco,$precoCusto,$catPost,$ref,$cor,$estoque,$desconto,$sizes,$imgPath,$ativo,$destaque,$id,$companyId]);
        sync_variants($pdo,$id,$sizes);
        flash('success','Produto atualizado.');
    } else {
        $pdo->prepare('INSERT INTO products (company_id,nome,descricao,preco,preco_custo,categoria,referencia,cor,estoque,desconto,sizes,imagem,ativo,destaque,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute([$companyId,$nome,$descricao,$preco,$precoCusto,$catPost,$ref,$cor,$estoque,$desconto,$sizes,$imgPath,$ativo,$destaque]);
        $newId = (int)$pdo->lastInsertId();
        sync_variants($pdo,$newId,$sizes);
        flash('success','Produto cadastrado com sucesso.');
    }
    redirect('products.php'.($retQ?'?'.$retQ:''));
}

/* ─── PRODUTO PARA EDIÇÃO ───────────────────────────────────────── */
$editingProduct = null;
if (($action === 'edit' || $action === 'create') && isset($_GET['id'])) {
    $s = $pdo->prepare('SELECT * FROM products WHERE id=? AND company_id=?');
    $s->execute([(int)$_GET['id'],$companyId]);
    $editingProduct = $s->fetch() ?: null;
}

/* ─── CATEGORIAS ────────────────────────────────────────────────── */
$cats = $pdo->prepare('SELECT DISTINCT categoria FROM products WHERE company_id=? AND categoria IS NOT NULL AND categoria<>"" ORDER BY categoria');
$cats->execute([$companyId]);
$categories = $cats->fetchAll(PDO::FETCH_COLUMN);

/* ─── STATS ─────────────────────────────────────────────────────── */
$statsRow = $pdo->prepare('SELECT COUNT(*) total, SUM(ativo) ativos, SUM(destaque) destaques, SUM(CASE WHEN ativo=0 THEN 1 ELSE 0 END) inativos FROM products WHERE company_id=?');
$statsRow->execute([$companyId]);
$stats = $statsRow->fetch();

/* ─── LISTAGEM ──────────────────────────────────────────────────── */
$where  = ['company_id=?'];
$params = [$companyId];
if ($q !== '')       { $where[] = 'nome LIKE ?';        $params[] = '%'.$q.'%'; }
if ($cat !== '')     { $where[] = 'categoria=?';         $params[] = $cat; }
if ($filterAt !== '') { $where[] = 'ativo=?';            $params[] = (int)$filterAt; }
$whereStr = implode(' AND ', $where);

$total     = (int)$pdo->prepare("SELECT COUNT(*) FROM products WHERE $whereStr")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM products WHERE $whereStr")->execute($params) : 0;
$cntStmt   = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $whereStr");
$cntStmt->execute($params);
$totalRows = (int)$cntStmt->fetchColumn();
$totalPages= max(1,(int)ceil($totalRows/$perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page-1)*$perPage;

$listStmt = $pdo->prepare("SELECT * FROM products WHERE $whereStr ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$products = $listStmt->fetchAll();

/* ─── RENDER ────────────────────────────────────────────────────── */
$flashSuccess = get_flash('success') ?? $flashSuccess;
$flashError   = get_flash('error')   ?? $flashError;

include __DIR__ . '/views/partials/header.php';
?>

<style>
/* ── Variables ── */
:root { --pr:1rem; }

/* ── Layout ── */
.pr-wrap { display:flex; gap:0; height:calc(100vh - 64px); margin:-1.5rem; overflow:hidden; font-family:'Inter',system-ui,sans-serif; }

/* ── Left panel (list) ── */
.pr-main { flex:1; display:flex; flex-direction:column; overflow:hidden; background:#f8fafc; }

/* ── Topbar ── */
.pr-topbar { padding:.75rem 1.25rem; background:#fff; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; gap:.75rem; flex-wrap:wrap; }
.pr-topbar h1 { font-size:1rem; font-weight:700; color:#0f172a; }
.pr-stats { display:flex; gap:1.25rem; }
.pr-stat { text-align:center; }
.pr-stat-val { font-size:1rem; font-weight:800; color:#6366f1; line-height:1; }
.pr-stat-label { font-size:.6rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }

/* ── Filters bar ── */
.pr-filters { padding:.6rem 1.25rem; background:#fff; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
.pr-search { position:relative; }
.pr-search input { padding:.45rem .75rem .45rem 2.1rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.82rem; outline:none; background:#f8fafc; width:220px; }
.pr-search input:focus { border-color:#6366f1; background:#fff; }
.pr-search svg { position:absolute; left:.65rem; top:50%; transform:translateY(-50%); color:#94a3b8; pointer-events:none; }
.pr-select { padding:.42rem .65rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.8rem; background:#f8fafc; color:#374151; outline:none; cursor:pointer; }
.pr-select:focus { border-color:#6366f1; }
.filter-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.35rem .75rem; border-radius:20px; font-size:.72rem; font-weight:600; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; text-decoration:none; transition:all .15s; }
.filter-chip:hover,.filter-chip.active { border-color:#6366f1; background:#f0f9ff; color:#6366f1; }
.filter-chip.active { background:#ede9fe; border-color:#6366f1; color:#6366f1; }
.filter-chip.green.active { background:#dcfce7; border-color:#16a34a; color:#15803d; }
.filter-chip.red.active   { background:#fee2e2; border-color:#dc2626; color:#dc2626; }

/* ── Product table ── */
.pr-table-wrap { flex:1; overflow-y:auto; padding:.75rem 1.25rem 1.25rem; }
.pr-table-wrap::-webkit-scrollbar { width:4px; }
.pr-table-wrap::-webkit-scrollbar-thumb { background:#e2e8f0; border-radius:2px; }

.pr-table { width:100%; border-collapse:collapse; font-size:.82rem; background:#fff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; }
.pr-table thead th { padding:.65rem .85rem; text-align:left; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.pr-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; cursor:pointer; }
.pr-table tbody tr:last-child { border-bottom:none; }
.pr-table tbody tr:hover { background:#f8fafc; }
.pr-table tbody tr.selected { background:#ede9fe; }
.pr-table td { padding:.6rem .85rem; vertical-align:middle; }

/* thumb */
.prod-thumb { width:36px; height:36px; border-radius:7px; object-fit:cover; background:#f1f5f9; border:1px solid #e2e8f0; flex-shrink:0; }
.prod-thumb-empty { width:36px; height:36px; border-radius:7px; background:#f1f5f9; border:1px dashed #e2e8f0; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:.8rem; flex-shrink:0; }
.prod-name-cell { display:flex; align-items:center; gap:.6rem; }
.prod-name { font-weight:600; color:#0f172a; }
.prod-ref  { font-size:.68rem; color:#94a3b8; }

/* badges */
.badge { display:inline-flex; align-items:center; gap:.25rem; font-size:.65rem; font-weight:700; padding:.15rem .5rem; border-radius:20px; white-space:nowrap; }
.badge-active   { background:#dcfce7; color:#15803d; }
.badge-inactive { background:#f1f5f9; color:#64748b; }
.badge-destaque { background:#fef9c3; color:#a16207; }
.badge-cat      { background:#ede9fe; color:#6d28d9; }

/* price */
.price-main { font-weight:700; color:#0f172a; white-space:nowrap; }
.price-custo { font-size:.68rem; color:#94a3b8; }
.price-margin { font-size:.68rem; color:#16a34a; font-weight:600; }

/* actions */
.row-actions { display:flex; align-items:center; gap:.3rem; }
.icon-btn { width:26px; height:26px; border-radius:6px; border:1.5px solid #e2e8f0; background:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; text-decoration:none; transition:all .15s; }
.icon-btn:hover { border-color:#6366f1; color:#6366f1; background:#f0f0ff; }
.icon-btn.danger:hover { border-color:#ef4444; color:#ef4444; background:#fef2f2; }

/* ── Right panel (form) ── */
.pr-panel { width:0; min-width:0; transition:width .25s cubic-bezier(.4,0,.2,1), min-width .25s; overflow:hidden; background:#fff; border-left:1px solid #e2e8f0; display:flex; flex-direction:column; }
.pr-panel.open { width:420px; min-width:420px; }

.panel-header { padding:1rem 1.25rem .75rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.panel-header h2 { font-size:.95rem; font-weight:700; color:#0f172a; }
.panel-close { width:28px; height:28px; border-radius:7px; border:1.5px solid #e2e8f0; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:1rem; transition:all .15s; }
.panel-close:hover { border-color:#ef4444; color:#ef4444; }
.panel-body { flex:1; overflow-y:auto; padding:1rem 1.25rem; }
.panel-body::-webkit-scrollbar { width:3px; }
.panel-body::-webkit-scrollbar-thumb { background:#e2e8f0; }
.panel-footer { padding:.75rem 1.25rem; border-top:1px solid #f1f5f9; display:flex; gap:.5rem; }

/* Form fields */
.field { margin-bottom:.85rem; }
.field label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin-bottom:.3rem; }
.fi { width:100%; padding:.5rem .75rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.85rem; background:#f8fafc; color:#0f172a; outline:none; transition:border-color .15s; font-family:inherit; }
.fi:focus { border-color:#6366f1; background:#fff; }
.fi-grid { display:grid; gap:.6rem; }
.fi-grid-2 { grid-template-columns:1fr 1fr; }
.fi-grid-3 { grid-template-columns:1fr 1fr 1fr; }

/* Image preview */
.img-preview-area { border:2px dashed #e2e8f0; border-radius:10px; padding:1rem; text-align:center; cursor:pointer; transition:all .2s; background:#f8fafc; position:relative; }
.img-preview-area:hover { border-color:#6366f1; background:#f5f3ff; }
.img-preview-area img { max-height:120px; max-width:100%; object-fit:contain; border-radius:6px; }
.img-preview-area input { position:absolute; inset:0; opacity:0; cursor:pointer; }

/* Sizes */
.sizes-wrap { display:flex; flex-wrap:wrap; gap:.4rem; }
.sz-chip { display:inline-flex; align-items:center; gap:.25rem; padding:.3rem .65rem; border-radius:20px; border:1.5px solid #e2e8f0; background:#fff; font-size:.75rem; font-weight:600; cursor:pointer; transition:all .15s; user-select:none; }
.sz-chip.on { border-color:#6366f1; background:#ede9fe; color:#4f46e5; }
.sz-chip input { display:none; }

/* Toggle switch */
.tog-wrap { display:flex; align-items:center; gap:.6rem; }
.tog { position:relative; width:38px; height:20px; }
.tog input { opacity:0; width:0; height:0; }
.tog-slider { position:absolute; inset:0; background:#e2e8f0; border-radius:20px; cursor:pointer; transition:.2s; }
.tog-slider::after { content:''; position:absolute; width:14px; height:14px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; }
.tog input:checked + .tog-slider { background:#22c55e; }
.tog input:checked + .tog-slider::after { left:21px; }
.tog-label { font-size:.8rem; color:#374151; font-weight:500; }

/* Btns */
.btn-save { flex:1; padding:.65rem; background:#6366f1; color:#fff; border:none; border-radius:8px; font-size:.875rem; font-weight:700; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#4f46e5; }
.btn-save.green { background:#16a34a; }
.btn-save.green:hover { background:#15803d; }
.btn-cancel { padding:.65rem 1rem; background:#f1f5f9; color:#374151; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.875rem; font-weight:600; cursor:pointer; transition:all .15s; }
.btn-cancel:hover { background:#e2e8f0; }

/* ── Pagination ── */
.pr-pagination { padding:.65rem 1.25rem; background:#fff; border-top:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem; }
.pag-info { font-size:.75rem; color:#94a3b8; }
.pag-btns { display:flex; gap:.25rem; }
.pag-btn { width:28px; height:28px; border-radius:6px; border:1.5px solid #e2e8f0; background:#fff; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:600; color:#475569; text-decoration:none; transition:all .15s; }
.pag-btn:hover { border-color:#6366f1; color:#6366f1; }
.pag-btn.cur { background:#6366f1; border-color:#6366f1; color:#fff; }
.pag-btn.disabled { opacity:.4; pointer-events:none; }

/* Flash */
.flash-ok  { padding:.65rem 1rem; border-radius:8px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.82rem; margin:.5rem 1.25rem 0; }
.flash-err { padding:.65rem 1rem; border-radius:8px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.82rem; margin:.5rem 1.25rem 0; }
</style>

<?php if ($flashSuccess): ?>
  <div class="flash-ok">✅ <?= sanitize($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="flash-err">⚠️ <?= sanitize($flashError) ?></div>
<?php endif; ?>

<div class="pr-wrap" id="pr-wrap">

  <!-- ══════════════ LEFT — LIST ══════════════ -->
  <div class="pr-main">

    <!-- Topbar -->
    <div class="pr-topbar">
      <div style="display:flex;align-items:center;gap:1rem;">
        <h1>Produtos / Serviços</h1>
        <div class="pr-stats">
          <div class="pr-stat"><div class="pr-stat-val"><?= (int)$stats['total'] ?></div><div class="pr-stat-label">Total</div></div>
          <div class="pr-stat"><div class="pr-stat-val" style="color:#22c55e;"><?= (int)$stats['ativos'] ?></div><div class="pr-stat-label">Ativos</div></div>
          <div class="pr-stat"><div class="pr-stat-val" style="color:#94a3b8;"><?= (int)$stats['inativos'] ?></div><div class="pr-stat-label">Inativos</div></div>
          <div class="pr-stat"><div class="pr-stat-val" style="color:#f59e0b;"><?= (int)$stats['destaques'] ?></div><div class="pr-stat-label">Destaque</div></div>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;">
        <a href="products_imports.php" style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;color:#475569;text-decoration:none;transition:all .15s;"
           onmouseover="this.style.borderColor='#6366f1';this.style.color='#6366f1'" onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Importar em Lote
        </a>
        <button onclick="openPanel(null)" style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:8px;background:#6366f1;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;transition:background .15s;"
                onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='#6366f1'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Novo Produto
        </button>
      </div>
    </div>

    <!-- Filters -->
    <form method="GET" id="filter-form">
      <div class="pr-filters">
        <!-- Busca -->
        <div class="pr-search">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar produto…" onchange="this.form.submit()">
        </div>

        <!-- Categoria -->
        <select name="categoria" class="pr-select" onchange="this.form.submit()">
          <option value="">Todas as categorias</option>
          <?php foreach($categories as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $cat===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>

        <!-- Status -->
        <?php
        $atUrl = fn($v) => '?'.http_build_query(array_filter(['q'=>$q,'categoria'=>$cat,'per_page'=>$perPage,'ativo'=>$v]));
        ?>
        <a href="<?= $atUrl('') ?>" class="filter-chip <?= $filterAt==='' ?'active':'' ?>">Todos</a>
        <a href="<?= $atUrl('1') ?>" class="filter-chip green <?= $filterAt==='1'?'active':'' ?>">
          <span style="width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block;"></span>Ativos
        </a>
        <a href="<?= $atUrl('0') ?>" class="filter-chip red <?= $filterAt==='0'?'active':'' ?>">
          <span style="width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block;"></span>Inativos
        </a>

        <!-- Per page -->
        <select name="per_page" class="pr-select" onchange="this.form.submit()" style="margin-left:auto;">
          <?php foreach([30,60,100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?>/página</option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="page" value="1">
      </div>
    </form>

    <!-- Table -->
    <div class="pr-table-wrap">
      <?php if (empty($products)): ?>
        <div style="text-align:center;padding:3rem;color:#94a3b8;">
          <div style="font-size:2.5rem;margin-bottom:.5rem;">📦</div>
          <p style="font-size:.875rem;">Nenhum produto encontrado.</p>
          <button onclick="openPanel(null)" style="margin-top:1rem;padding:.6rem 1.2rem;background:#6366f1;color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;">+ Cadastrar produto</button>
        </div>
      <?php else: ?>
      <table class="pr-table">
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>Produto</th>
            <th>Categoria</th>
            <th>Preço Venda</th>
            <th>Custo / Margem</th>
            <th>Estoque</th>
            <th>Status</th>
            <th style="width:100px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($products as $p):
            $preco  = (float)$p['preco'];
            $custo  = isset($p['preco_custo']) ? (float)$p['preco_custo'] : 0;
            $margem = ($custo > 0 && $preco > 0) ? round(($preco-$custo)/$preco*100) : null;
            $estoque= isset($p['estoque']) ? (int)$p['estoque'] : '—';
          ?>
          <tr onclick="openPanel(<?= (int)$p['id'] ?>)" id="row-<?= (int)$p['id'] ?>">
            <td onclick="event.stopPropagation()">
              <?php if(!empty($p['imagem'])): ?>
                <img src="<?= sanitize(image_url($p['imagem'])) ?>" class="prod-thumb" alt="">
              <?php else: ?>
                <div class="prod-thumb-empty">📷</div>
              <?php endif; ?>
            </td>
            <td>
              <div class="prod-name-cell">
                <div>
                  <div class="prod-name"><?= sanitize($p['nome']) ?></div>
                  <?php if(!empty($p['referencia'])): ?>
                    <div class="prod-ref">REF: <?= sanitize($p['referencia']) ?><?= !empty($p['cor']) ? ' · '.$p['cor'] : '' ?></div>
                  <?php elseif(!empty($p['cor'])): ?>
                    <div class="prod-ref"><?= sanitize($p['cor']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?php if(!empty($p['categoria'])): ?>
                <span class="badge badge-cat"><?= sanitize($p['categoria']) ?></span>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="price-main">R$ <?= number_format($preco,2,',','.') ?></div>
              <?php if(isset($p['desconto']) && $p['desconto']>0): ?>
                <div class="prod-ref" style="color:#f59e0b;">↓ <?= (int)$p['desconto'] ?>% desc.</div>
              <?php endif; ?>
            </td>
            <td>
              <?php if($custo > 0): ?>
                <div class="price-custo">Custo: R$ <?= number_format($custo,2,',','.') ?></div>
                <?php if($margem !== null): ?>
                  <div class="price-margin">Margem: <?= $margem ?>%</div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if(is_numeric($estoque)): ?>
                <span style="font-weight:700;color:<?= $estoque==0?'#ef4444':($estoque<5?'#f59e0b':'#0f172a') ?>;"><?= $estoque ?></span>
                <?php if($estoque==0): ?>
                  <div style="font-size:.65rem;color:#ef4444;">Sem estoque</div>
                <?php elseif($estoque<5): ?>
                  <div style="font-size:.65rem;color:#f59e0b;">Estoque baixo</div>
                <?php endif; ?>
              <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:.2rem;">
                <span class="badge <?= $p['ativo']?'badge-active':'badge-inactive' ?>"><?= $p['ativo']?'Ativo':'Inativo' ?></span>
                <?php if($p['destaque']): ?><span class="badge badge-destaque">⭐ Destaque</span><?php endif; ?>
              </div>
            </td>
            <td onclick="event.stopPropagation()">
              <div class="row-actions">
                <a href="?action=toggle_ativo&id=<?= (int)$p['id'] ?>&<?= $backQs ?>" class="icon-btn" title="<?= $p['ativo']?'Desativar':'Ativar' ?>">
                  <?= $p['ativo']
                    ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17.94 17.94A10 10 0 0112 20C6.48 20 2 15.52 2 10c0-1.48.33-2.88.94-4.12M9.9 4.24A9.12 9.12 0 0112 4c5.52 0 10 4.48 10 10 0 .74-.08 1.46-.24 2.15M12 12h.01"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
                    : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
                  ?>
                </a>
                <a href="product_stock.php?id=<?= (int)$p['id'] ?>" class="icon-btn" title="Estoque">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </a>
                <a href="?action=delete&id=<?= (int)$p['id'] ?>&<?= $backQs ?>" class="icon-btn danger" title="Remover"
                   onclick="return confirm('Remover <?= addslashes(htmlspecialchars($p['nome'])) ?>?')">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if($totalPages > 1): ?>
    <div class="pr-pagination">
      <span class="pag-info"><?= $offset+1 ?>–<?= min($offset+$perPage,$totalRows) ?> de <?= $totalRows ?> produtos</span>
      <div class="pag-btns">
        <?php
        $pgUrl = fn($p) => '?'.http_build_query(array_filter(['q'=>$q,'categoria'=>$cat,'per_page'=>$perPage,'ativo'=>$filterAt,'page'=>$p]));
        for($pg=1;$pg<=$totalPages;$pg++):
          if($totalPages > 7 && abs($pg-$page)>2 && $pg!==1 && $pg!==$totalPages){ if($pg===2||$pg===$totalPages-1) echo '<span style="padding:0 .25rem;color:#94a3b8;">…</span>'; continue; }
        ?>
          <a href="<?= $pgUrl($pg) ?>" class="pag-btn <?= $pg===$page?'cur':'' ?>"><?= $pg ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════════ RIGHT — FORM PANEL ══════════════ -->
  <div class="pr-panel" id="pr-panel">
    <div class="panel-header">
      <h2 id="panel-title">Novo Produto</h2>
      <button class="panel-close" onclick="closePanel()">✕</button>
    </div>
    <div class="panel-body">
      <form method="POST" enctype="multipart/form-data" id="product-form">
        <input type="hidden" name="id" id="f-id" value="0">
        <input type="hidden" name="current_image" id="f-img" value="">
        <input type="hidden" name="return_q" value="<?= htmlspecialchars($q) ?>">
        <input type="hidden" name="return_cat" value="<?= htmlspecialchars($cat) ?>">
        <input type="hidden" name="return_page" value="<?= $page ?>">
        <input type="hidden" name="return_per_page" value="<?= $perPage ?>">
        <input type="hidden" name="return_ativo" value="<?= htmlspecialchars($filterAt) ?>">

        <!-- Imagem -->
        <div class="field">
          <label>Foto do Produto</label>
          <div class="img-preview-area" id="img-preview-area">
            <div id="img-placeholder">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" style="margin:0 auto .5rem;display:block;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              <p style="font-size:.75rem;color:#94a3b8;">Clique para adicionar foto</p>
            </div>
            <img id="img-preview" src="" alt="" style="display:none;max-height:120px;max-width:100%;object-fit:contain;border-radius:6px;">
            <input type="file" name="imagem" id="f-imagem" accept="image/*" onchange="previewImg(this)">
          </div>
        </div>

        <!-- Nome -->
        <div class="field">
          <label>Nome *</label>
          <input class="fi" type="text" name="nome" id="f-nome" required placeholder="Ex: Camiseta Nike Dri-Fit">
        </div>

        <!-- Ref + Cor -->
        <div class="field fi-grid fi-grid-2">
          <div>
            <label>Referência / SKU</label>
            <input class="fi" type="text" name="referencia" id="f-ref" placeholder="REF-001">
          </div>
          <div>
            <label>Cor</label>
            <input class="fi" type="text" name="cor" id="f-cor" placeholder="Preto, Azul...">
          </div>
        </div>

        <!-- Preços -->
        <div class="field fi-grid fi-grid-2">
          <div>
            <label>Preço de Custo R$</label>
            <input class="fi" type="text" name="preco_custo" id="f-custo" placeholder="0,00" oninput="calcMargem()">
          </div>
          <div>
            <label>Preço de Venda R$ *</label>
            <input class="fi" type="text" name="preco" id="f-preco" placeholder="0,00" required oninput="calcMargem()">
          </div>
        </div>
        <div id="margem-preview" style="margin-top:-.5rem;margin-bottom:.75rem;font-size:.75rem;color:#16a34a;font-weight:600;display:none;"></div>

        <!-- Estoque + Desconto -->
        <div class="field fi-grid fi-grid-2">
          <div>
            <label>Qtd em Estoque</label>
            <input class="fi" type="number" name="estoque" id="f-estoque" min="0" value="0">
          </div>
          <div>
            <label>Desconto %</label>
            <input class="fi" type="number" name="desconto" id="f-desconto" min="0" max="100" value="0">
          </div>
        </div>

        <!-- Categoria -->
        <div class="field">
          <label>Categoria</label>
          <input class="fi" type="text" name="categoria" id="f-cat" list="cat-list" placeholder="Tênis, Camisetas, Serviços...">
          <datalist id="cat-list">
            <?php foreach($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
          </datalist>
        </div>

        <!-- Descrição -->
        <div class="field">
          <label>Descrição</label>
          <textarea class="fi" name="descricao" id="f-desc" rows="3" placeholder="Detalhes do produto..."></textarea>
        </div>

        <!-- Tamanhos -->
        <div class="field">
          <label>Tamanhos Disponíveis</label>
          <div style="margin-bottom:.5rem;">
            <select id="sz-preset" class="fi" style="font-size:.78rem;" onchange="renderSizes(this.value)">
              <option value="">— Selecionar tipo —</option>
              <option value="roupa">Roupa (P / M / G / GG / XGG)</option>
              <option value="calcado">Calçado (37 → 45)</option>
              <option value="infantil">Infantil (2 → 16)</option>
            </select>
          </div>
          <div class="sizes-wrap" id="sz-wrap"></div>
          <input type="hidden" name="sizes" id="f-sizes">
        </div>

        <!-- Toggles -->
        <div class="field" style="display:flex;gap:1.5rem;">
          <div class="tog-wrap">
            <label class="tog"><input type="checkbox" name="ativo" id="f-ativo" value="1"><span class="tog-slider"></span></label>
            <span class="tog-label">Ativo na loja</span>
          </div>
          <div class="tog-wrap">
            <label class="tog"><input type="checkbox" name="destaque" id="f-destaque" value="1"><span class="tog-slider"></span></label>
            <span class="tog-label">Destaque</span>
          </div>
        </div>
      </form>
    </div>
    <div class="panel-footer">
      <button type="button" class="btn-cancel" onclick="closePanel()">Cancelar</button>
      <button type="submit" form="product-form" class="btn-save green">💾 Salvar Produto</button>
    </div>
  </div>

</div>

<!-- Products data for panel population -->
<script>
const PRODUCTS_DATA = <?= json_encode(array_column($products, null, 'id')) ?>;
const BASE_URL = '<?= rtrim((string)BASE_URL,'/') ?>';

// ── Panel ──
function openPanel(id) {
  const panel = document.getElementById('pr-panel');
  panel.classList.add('open');

  if (id) {
    const p = PRODUCTS_DATA[id];
    if (!p) return;
    document.getElementById('panel-title').textContent = 'Editar Produto';
    document.getElementById('f-id').value         = p.id;
    document.getElementById('f-nome').value        = p.nome || '';
    document.getElementById('f-ref').value         = p.referencia || '';
    document.getElementById('f-cor').value         = p.cor || '';
    document.getElementById('f-custo').value       = p.preco_custo ? fmt(p.preco_custo) : '';
    document.getElementById('f-preco').value       = fmt(p.preco);
    document.getElementById('f-estoque').value     = p.estoque || 0;
    document.getElementById('f-desconto').value    = p.desconto || 0;
    document.getElementById('f-cat').value         = p.categoria || '';
    document.getElementById('f-desc').value        = p.descricao || '';
    document.getElementById('f-ativo').checked     = parseInt(p.ativo) === 1;
    document.getElementById('f-destaque').checked  = parseInt(p.destaque) === 1;
    document.getElementById('f-img').value         = p.imagem || '';
    document.getElementById('f-sizes').value       = p.sizes || '';

    // Imagem preview
    const prevImg = document.getElementById('img-preview');
    const prevPh  = document.getElementById('img-placeholder');
    if (p.imagem) {
      prevImg.src = BASE_URL + '/' + p.imagem;
      prevImg.style.display = 'block';
      prevPh.style.display  = 'none';
    } else {
      prevImg.style.display = 'none';
      prevPh.style.display  = 'block';
    }

    // Sizes
    autoDetectSizes(p.sizes || '');
    calcMargem();

    // Highlight row
    document.querySelectorAll('.pr-table tbody tr').forEach(r => r.classList.remove('selected'));
    const row = document.getElementById('row-'+id);
    if (row) { row.classList.add('selected'); row.scrollIntoView({block:'nearest'}); }
  } else {
    // New product
    document.getElementById('panel-title').textContent = 'Novo Produto';
    document.getElementById('product-form').reset();
    document.getElementById('f-id').value = '0';
    document.getElementById('f-img').value = '';
    document.getElementById('img-preview').style.display = 'none';
    document.getElementById('img-placeholder').style.display = 'block';
    document.getElementById('sz-wrap').innerHTML = '';
    document.getElementById('f-sizes').value = '';
    document.getElementById('margem-preview').style.display = 'none';
    document.querySelectorAll('.pr-table tbody tr').forEach(r => r.classList.remove('selected'));
  }
}

function closePanel() {
  document.getElementById('pr-panel').classList.remove('open');
  document.querySelectorAll('.pr-table tbody tr').forEach(r => r.classList.remove('selected'));
}

// ── Imagem preview ──
function previewImg(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('img-preview');
    img.src = e.target.result;
    img.style.display = 'block';
    document.getElementById('img-placeholder').style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
}

// ── Margem ──
function fmt(v) { return parseFloat(v||0).toFixed(2).replace('.',','); }
function parsePrice(s) {
  s = String(s).replace(/[^0-9,\.]/g,'');
  if (s.includes(',') && !s.includes('.')) s = s.replace(',','.');
  else if (s.includes(',') && s.includes('.')) { s = s.replace('.',''); s = s.replace(',','.'); }
  return parseFloat(s) || 0;
}
function calcMargem() {
  const c = parsePrice(document.getElementById('f-custo').value);
  const v = parsePrice(document.getElementById('f-preco').value);
  const el = document.getElementById('margem-preview');
  if (c > 0 && v > 0) {
    const m = ((v-c)/v*100).toFixed(1);
    const markup = ((v-c)/c*100).toFixed(1);
    el.textContent = `Margem: ${m}%  ·  Markup: ${markup}%`;
    el.style.display = 'block';
    el.style.color = m >= 30 ? '#16a34a' : m >= 15 ? '#d97706' : '#dc2626';
  } else { el.style.display = 'none'; }
}

// ── Sizes ──
const SIZE_PRESETS = {
  roupa:    ['P','M','G','GG','XGG'],
  calcado:  ['37','38','39','40','41','42','43','44','45'],
  infantil: ['2','4','6','8','10','12','14','16'],
};

function renderSizes(preset, checked=[]) {
  const wrap = document.getElementById('sz-wrap');
  wrap.innerHTML = '';
  const sizes = SIZE_PRESETS[preset] || [];
  sizes.forEach(sz => {
    const label = document.createElement('label');
    label.className = 'sz-chip' + (checked.includes(sz)?' on':'');
    label.innerHTML = `<input type="checkbox" value="${sz}" ${checked.includes(sz)?'checked':''}>${sz}`;
    label.querySelector('input').addEventListener('change', function() {
      label.classList.toggle('on', this.checked);
      updateSizesHidden();
    });
    wrap.appendChild(label);
  });
  updateSizesHidden();
}

function updateSizesHidden() {
  const checked = Array.from(document.querySelectorAll('#sz-wrap input:checked')).map(i=>i.value);
  document.getElementById('f-sizes').value = checked.join(',');
}

function autoDetectSizes(sizesStr) {
  if (!sizesStr) { document.getElementById('sz-wrap').innerHTML = ''; return; }
  const arr = sizesStr.split(',').map(s=>s.trim()).filter(Boolean);
  let preset = '';
  if (arr.some(v => ['P','M','G','GG','XGG'].includes(v))) preset = 'roupa';
  else if (arr.some(v => parseInt(v) >= 37 && parseInt(v) <= 45)) preset = 'calcado';
  else if (arr.some(v => parseInt(v) >= 2 && parseInt(v) <= 16)) preset = 'infantil';
  if (preset) {
    document.getElementById('sz-preset').value = preset;
    renderSizes(preset, arr);
  } else {
    document.getElementById('sz-wrap').innerHTML = '';
  }
}

// ── Close on Escape ──
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

// ── Auto-open if URL has action=edit or action=create ──
<?php if ($action === 'edit' && $editingProduct): ?>
  document.addEventListener('DOMContentLoaded', () => openPanel(<?= (int)$editingProduct['id'] ?>));
<?php elseif ($action === 'create'): ?>
  document.addEventListener('DOMContentLoaded', () => openPanel(null));
<?php endif; ?>
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>