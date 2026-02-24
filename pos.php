<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Dados ──
$clientsStmt = $pdo->prepare('SELECT id, nome, telefone_principal, whatsapp FROM clients WHERE company_id=? ORDER BY nome');
$clientsStmt->execute([$companyId]);
$clients = $clientsStmt->fetchAll();

$productsStmt = $pdo->prepare('SELECT id, nome, preco, categoria FROM products WHERE company_id=? AND ativo=1 ORDER BY categoria, nome');
$productsStmt->execute([$companyId]);
$products = $productsStmt->fetchAll();

// Categorias únicas
$categories = array_unique(array_filter(array_column($products, 'categoria')));
sort($categories);

// ── Últimas vendas do dia ──
$todayStmt = $pdo->prepare("
    SELECT o.id, o.total, o.status, o.created_at, c.nome as cliente_nome
    FROM orders o
    LEFT JOIN clients c ON c.id = o.client_id
    WHERE o.company_id=? AND o.origem='pdv' AND DATE(o.created_at)=CURDATE()
    ORDER BY o.created_at DESC LIMIT 5
");
$todayStmt->execute([$companyId]);
$todaySales = $todayStmt->fetchAll();

$todayTotalStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE company_id=? AND origem='pdv' AND DATE(created_at)=CURDATE() AND status='concluido'");
$todayTotalStmt->execute([$companyId]);
$todayTotal = (float)$todayTotalStmt->fetchColumn();

$todayCountStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE company_id=? AND origem='pdv' AND DATE(created_at)=CURDATE()");
$todayCountStmt->execute([$companyId]);
$todayCount = (int)$todayCountStmt->fetchColumn();

$flashError = $flashSuccess = null;
$lastOrderId = null;

// ── POST: finalizar venda ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId       = (int)($_POST['client_id'] ?? 0);
    $newClientName  = trim($_POST['new_client_name'] ?? '');
    $newClientPhone = trim($_POST['new_client_phone'] ?? '');
    $noClient       = isset($_POST['no_client']);
    $origem         = $_POST['origem'] ?? 'pdv';
    $desconto       = (float)($_POST['desconto'] ?? 0);
    $pagamento      = $_POST['forma_pagamento'] ?? 'dinheiro';
    $itemsJson      = $_POST['items'] ?? '[]';
    $items          = json_decode($itemsJson, true);

    if (!$items || !is_array($items) || count($items) === 0) {
        $flashError = 'Adicione ao menos um item ao carrinho.';
    } else {
        if (!$clientId && $newClientName) {
            $wa = preg_replace('/\D/','',$newClientPhone);
            if ($wa && !str_starts_with($wa,'55') && strlen($wa)>=10) $wa='55'.$wa;
            $pdo->prepare('INSERT INTO clients (company_id,nome,telefone_principal,whatsapp,tags,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())')
                ->execute([$companyId, $newClientName, $newClientPhone, $wa ?: $newClientPhone, 'pdv']);
            $clientId = (int)$pdo->lastInsertId();
        }
        if (!$clientId && !$noClient) {
            $flashError = 'Selecione um cliente, cadastre um novo ou marque como venda sem cadastro.';
        } else {
            $subtotal = 0;
            foreach ($items as $it) $subtotal += ($it['qty']??0) * ($it['price']??0);
            $total = max(0, $subtotal - $desconto);

            $pdo->prepare('INSERT INTO orders (company_id,client_id,origem,status,total,forma_pagamento,desconto,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')
                ->execute([$companyId, $clientId ?: null, $origem, 'concluido', $total, $pagamento, $desconto]);
            $orderId = (int)$pdo->lastInsertId();
            $lastOrderId = $orderId;

            $stmtItem = $pdo->prepare('INSERT INTO order_items (order_id,product_id,quantidade,preco_unitario,subtotal) VALUES (?,?,?,?,?)');
            foreach ($items as $it) {
                $stmtItem->execute([$orderId, $it['id'], $it['qty'], $it['price'], $it['qty']*$it['price']]);
            }

            if ($clientId) {
                $pdo->prepare('UPDATE clients SET ltv_total=COALESCE(ltv_total,0)+?,updated_at=NOW() WHERE id=?')->execute([$total,$clientId]);
            }

            log_action($pdo,(int)$companyId,(int)$userId,'pdv_venda','Pedido #'.$orderId.' R$'.$total.' '.$pagamento);
            $flashSuccess = 'Venda #'.$orderId.' registrada — '.strtoupper($pagamento).' — R$ '.number_format($total,2,',','.');
        }
    }
}

include __DIR__ . '/views/partials/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap');

/* ── Reset & base ── */
*, *::before, *::after { box-sizing: border-box; }

/* ── POS Layout ── */
.pos-wrap {
  display: grid;
  grid-template-columns: 1fr 380px;
  grid-template-rows: auto 1fr;
  gap: 0;
  height: calc(100vh - 64px);
  background: #f0f2f5;
  font-family: 'Outfit', sans-serif;
  margin: -1.5rem;          /* sai do padding do layout */
  overflow: hidden;
}

/* ── Top bar ── */
.pos-topbar {
  grid-column: 1 / -1;
  background: #1a1d23;
  padding: .6rem 1.25rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  border-bottom: 1px solid #2a2d35;
}
.pos-topbar-brand { display:flex; align-items:center; gap:.6rem; }
.pos-topbar-brand span { font-size:.9rem; font-weight:700; color:#fff; letter-spacing:.02em; }
.pos-topbar-brand .dot { width:8px; height:8px; border-radius:50%; background:#22c55e; animation:pulse-dot 2s infinite; }
@keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.4} }

.pos-stat { display:flex; flex-direction:column; align-items:center; }
.pos-stat-val { font-family:'JetBrains Mono',monospace; font-size:1rem; font-weight:700; color:#fff; line-height:1; }
.pos-stat-val.green { color:#22c55e; }
.pos-stat-label { font-size:.6rem; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin-top:.1rem; }
.pos-topbar-stats { display:flex; gap:2rem; }

.pos-btn-sm { padding:.4rem .9rem; border-radius:7px; font-size:.75rem; font-weight:600; border:none; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:.35rem; transition:all .15s; }
.pos-btn-sm.outline { background:transparent; color:#9ca3af; border:1px solid #374151; }
.pos-btn-sm.outline:hover { color:#fff; border-color:#6b7280; }

/* ── Left: produtos ── */
.pos-products {
  display: flex;
  flex-direction: column;
  background: #f0f2f5;
  overflow: hidden;
}

.pos-search-bar {
  padding: .85rem 1.1rem;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  gap: .6rem;
  align-items: center;
}
.pos-search-wrap { position:relative; flex:1; }
.pos-search-wrap input {
  width:100%;
  padding:.6rem .9rem .6rem 2.5rem;
  border:1.5px solid #e5e7eb;
  border-radius:9px;
  font-size:.9rem;
  font-family:'Outfit',sans-serif;
  background:#f8fafc;
  color:#111;
  outline:none;
  transition:border-color .15s;
}
.pos-search-wrap input:focus { border-color:#6366f1; background:#fff; }
.pos-search-wrap svg { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none; }

.cat-tabs {
  display: flex;
  gap: .3rem;
  padding: .6rem 1.1rem;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  overflow-x: auto;
  scrollbar-width: none;
}
.cat-tabs::-webkit-scrollbar { display:none; }
.cat-tab {
  flex-shrink: 0;
  padding: .3rem .8rem;
  border-radius: 20px;
  font-size:.75rem;
  font-weight:600;
  border: 1.5px solid #e5e7eb;
  background: #fff;
  color: #64748b;
  cursor: pointer;
  transition:all .15s;
  white-space:nowrap;
}
.cat-tab:hover { border-color:#6366f1; color:#6366f1; }
.cat-tab.active { background:#6366f1; border-color:#6366f1; color:#fff; }

.products-grid {
  flex: 1;
  overflow-y: auto;
  padding: .85rem 1.1rem;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: .7rem;
  align-content: start;
}
.products-grid::-webkit-scrollbar { width:4px; }
.products-grid::-webkit-scrollbar-track { background:transparent; }
.products-grid::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:2px; }

.prod-card {
  background: #fff;
  border: 1.5px solid #e5e7eb;
  border-radius: 12px;
  padding: .9rem .8rem;
  cursor: pointer;
  transition: all .15s;
  display: flex;
  flex-direction: column;
  gap: .35rem;
  position: relative;
  overflow: hidden;
}
.prod-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: #6366f1;
  transform: scaleX(0);
  transform-origin: left;
  transition: transform .2s;
}
.prod-card:hover { border-color:#6366f1; box-shadow:0 4px 16px rgba(99,102,241,.12); transform:translateY(-2px); }
.prod-card:hover::before { transform:scaleX(1); }
.prod-card:active { transform:scale(.97); }
.prod-card.added { border-color:#22c55e; background:#f0fdf4; }

.prod-cat-badge {
  font-size:.6rem;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.05em;
  color:#94a3b8;
}
.prod-name {
  font-size:.82rem;
  font-weight:600;
  color:#0f172a;
  line-height:1.3;
  flex:1;
}
.prod-price {
  font-family:'JetBrains Mono',monospace;
  font-size:.95rem;
  font-weight:700;
  color:#6366f1;
}
.prod-add-hint {
  font-size:.65rem;
  color:#94a3b8;
  display:flex;
  align-items:center;
  gap:.2rem;
}

/* ── Right: carrinho ── */
.pos-cart {
  display: flex;
  flex-direction: column;
  background: #1a1d23;
  border-left: 1px solid #2a2d35;
  overflow: hidden;
}

.cart-header {
  padding: 1rem 1.25rem .75rem;
  border-bottom: 1px solid #2a2d35;
}
.cart-header h2 {
  font-size:1rem;
  font-weight:700;
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.cart-count { background:#6366f1; color:#fff; font-size:.7rem; font-weight:700; padding:.15rem .5rem; border-radius:20px; }

/* Canal de venda */
.canal-tabs { display:flex; gap:.4rem; margin-top:.75rem; }
.canal-tab {
  flex:1;
  padding:.4rem .5rem;
  border-radius:8px;
  border:1.5px solid #374151;
  background:transparent;
  color:#6b7280;
  font-size:.72rem;
  font-weight:600;
  cursor:pointer;
  text-align:center;
  transition:all .15s;
}
.canal-tab.active { border-color:#6366f1; background:#6366f1; color:#fff; }

/* Client selector */
.cart-client {
  padding: .75rem 1.25rem;
  border-bottom: 1px solid #2a2d35;
}
.cart-section-label {
  font-size:.65rem;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.07em;
  color:#4b5563;
  margin-bottom:.4rem;
}
.pos-select, .pos-input {
  width:100%;
  padding:.5rem .75rem;
  border:1.5px solid #374151;
  border-radius:8px;
  background:#111318;
  color:#e5e7eb;
  font-size:.82rem;
  font-family:'Outfit',sans-serif;
  outline:none;
  transition:border-color .15s;
}
.pos-select:focus, .pos-input:focus { border-color:#6366f1; }
.pos-select option { background:#1a1d23; }
.pos-input::placeholder { color:#4b5563; }
.pos-input-row { display:grid; grid-template-columns:1fr 1fr; gap:.4rem; margin-top:.4rem; }
.new-client-section { display:none; margin-top:.4rem; }
.new-client-section.visible { display:block; }

.no-client-check { display:flex; align-items:center; gap:.5rem; margin-top:.5rem; cursor:pointer; }
.no-client-check input { width:14px; height:14px; accent-color:#6366f1; cursor:pointer; }
.no-client-check span { font-size:.75rem; color:#6b7280; }

/* Cart items list */
.cart-items {
  flex:1;
  overflow-y:auto;
  padding:.5rem 0;
  scrollbar-width:thin;
  scrollbar-color:#374151 transparent;
}
.cart-items::-webkit-scrollbar { width:3px; }
.cart-items::-webkit-scrollbar-thumb { background:#374151; border-radius:2px; }

.cart-empty {
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  height:100%;
  color:#374151;
  gap:.5rem;
}
.cart-empty svg { opacity:.25; }
.cart-empty p { font-size:.82rem; }

.cart-item {
  display:flex;
  align-items:center;
  gap:.6rem;
  padding:.6rem 1.25rem;
  border-bottom:1px solid #1f2229;
  transition:background .1s;
}
.cart-item:hover { background:#1f2229; }
.ci-info { flex:1; min-width:0; }
.ci-name { font-size:.82rem; font-weight:600; color:#e5e7eb; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ci-price { font-family:'JetBrains Mono',monospace; font-size:.72rem; color:#6b7280; }
.ci-qty-ctrl { display:flex; align-items:center; gap:0; }
.ci-qty-btn {
  width:22px; height:22px;
  border:none;
  border-radius:5px;
  background:#2a2d35;
  color:#9ca3af;
  font-size:.85rem;
  font-weight:700;
  cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:background .1s;
}
.ci-qty-btn:hover { background:#374151; color:#fff; }
.ci-qty { font-family:'JetBrains Mono',monospace; font-size:.8rem; font-weight:700; color:#fff; min-width:24px; text-align:center; }
.ci-subtotal { font-family:'JetBrains Mono',monospace; font-size:.82rem; font-weight:700; color:#a78bfa; min-width:60px; text-align:right; }
.ci-remove { width:18px; height:18px; background:transparent; border:none; cursor:pointer; color:#374151; transition:color .1s; display:flex; align-items:center; justify-content:center; }
.ci-remove:hover { color:#ef4444; }

/* Cart footer */
.cart-footer { padding:.85rem 1.25rem; border-top:1px solid #2a2d35; }

.cart-totals { margin-bottom:.75rem; }
.cart-total-row { display:flex; justify-content:space-between; align-items:center; padding:.2rem 0; }
.cart-total-label { font-size:.78rem; color:#6b7280; }
.cart-total-val { font-family:'JetBrains Mono',monospace; font-size:.82rem; color:#9ca3af; }
.cart-total-row.grand { border-top:1px solid #2a2d35; padding-top:.5rem; margin-top:.25rem; }
.cart-total-row.grand .cart-total-label { font-size:.9rem; font-weight:700; color:#e5e7eb; }
.cart-total-row.grand .cart-total-val { font-size:1.25rem; font-weight:800; color:#fff; }

.desconto-row { display:flex; align-items:center; gap:.5rem; margin-bottom:.6rem; }
.desconto-row label { font-size:.72rem; color:#6b7280; white-space:nowrap; }
.desconto-input {
  width:90px;
  padding:.35rem .6rem;
  border:1.5px solid #374151;
  border-radius:7px;
  background:#111318;
  color:#fbbf24;
  font-family:'JetBrains Mono',monospace;
  font-size:.82rem;
  outline:none;
  text-align:right;
}
.desconto-input:focus { border-color:#fbbf24; }

/* Formas de pagamento */
.pagamento-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.35rem; margin-bottom:.75rem; }
.pag-btn {
  padding:.45rem .3rem;
  border-radius:8px;
  border:1.5px solid #374151;
  background:transparent;
  color:#6b7280;
  font-size:.68rem;
  font-weight:600;
  cursor:pointer;
  text-align:center;
  transition:all .15s;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:.2rem;
}
.pag-btn:hover { border-color:#6366f1; color:#a78bfa; }
.pag-btn.active { border-color:#22c55e; background:rgba(34,197,94,.1); color:#22c55e; }

.btn-finalizar {
  width:100%;
  padding:.9rem;
  background:linear-gradient(135deg,#22c55e,#16a34a);
  color:#fff;
  border:none;
  border-radius:10px;
  font-family:'Outfit',sans-serif;
  font-size:.95rem;
  font-weight:700;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:.5rem;
  transition:all .15s;
  letter-spacing:.02em;
}
.btn-finalizar:hover { background:linear-gradient(135deg,#16a34a,#15803d); transform:translateY(-1px); box-shadow:0 8px 24px rgba(34,197,94,.25); }
.btn-finalizar:active { transform:scale(.98); }
.btn-limpar {
  width:100%;
  padding:.55rem;
  background:transparent;
  color:#4b5563;
  border:1px solid #2a2d35;
  border-radius:8px;
  font-size:.78rem;
  font-weight:600;
  cursor:pointer;
  margin-top:.4rem;
  transition:all .15s;
}
.btn-limpar:hover { color:#ef4444; border-color:#ef4444; }

/* ── Toast de sucesso ── */
.pos-toast {
  position:fixed;
  top:1.5rem;
  left:50%;
  transform:translateX(-50%) translateY(-80px);
  background:#1a1d23;
  border:1px solid #22c55e;
  color:#fff;
  padding:1rem 1.5rem;
  border-radius:12px;
  display:flex;
  align-items:center;
  gap:.75rem;
  z-index:9999;
  transition:transform .35s cubic-bezier(.34,1.56,.64,1);
  min-width:300px;
  max-width:420px;
  box-shadow:0 16px 48px rgba(0,0,0,.4);
  font-size:.875rem;
}
.pos-toast.show { transform:translateX(-50%) translateY(0); }
.pos-toast .toast-icon { width:36px; height:36px; border-radius:9px; background:rgba(34,197,94,.15); display:flex; align-items:center; justify-content:center; flex-shrink:0; }

/* ── Histórico lateral (mini) ── */
.recent-sales {
  padding:.6rem 1.25rem .3rem;
  border-bottom:1px solid #2a2d35;
}
.rs-item {
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:.3rem 0;
  font-size:.72rem;
  border-bottom:1px solid #1f2229;
}
.rs-item:last-child { border-bottom:none; }
.rs-name { color:#9ca3af; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:130px; }
.rs-val { font-family:'JetBrains Mono',monospace; color:#a78bfa; font-weight:700; }

/* ── Flash ── */
.pos-flash { position:fixed; top:70px; left:50%; transform:translateX(-50%); z-index:9000; min-width:300px; text-align:center; }
</style>

<?php if ($flashError): ?>
  <div class="pos-flash">
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:.75rem 1.25rem;border-radius:10px;font-size:.85rem;">
      ⚠️ <?= sanitize($flashError) ?>
    </div>
  </div>
<?php endif; ?>

<input type="hidden" id="flash-success" value="<?= $flashSuccess ? sanitize($flashSuccess) : '' ?>">
<input type="hidden" id="last-order-id" value="<?= (int)$lastOrderId ?>">

<div class="pos-wrap">

  <!-- ── TOP BAR ── -->
  <div class="pos-topbar">
    <div class="pos-topbar-brand">
      <span class="dot"></span>
      <span>PDV — For Men Store</span>
    </div>
    <div class="pos-topbar-stats">
      <div class="pos-stat">
        <span class="pos-stat-val green">R$ <?= number_format($todayTotal,2,',','.') ?></span>
        <span class="pos-stat-label">Faturamento hoje</span>
      </div>
      <div class="pos-stat">
        <span class="pos-stat-val"><?= $todayCount ?></span>
        <span class="pos-stat-label">Vendas hoje</span>
      </div>
      <div class="pos-stat">
        <span class="pos-stat-val"><?= count($products) ?></span>
        <span class="pos-stat-label">Produtos</span>
      </div>
    </div>
    <div style="display:flex;gap:.5rem;">
      <a href="orders.php" class="pos-btn-sm outline">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        Pedidos
      </a>
      <a href="clients.php" class="pos-btn-sm outline">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Clientes
      </a>
    </div>
  </div>

  <!-- ── LEFT: PRODUTOS ── -->
  <div class="pos-products">
    <!-- Search -->
    <div class="pos-search-bar">
      <div class="pos-search-wrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="prod-search" placeholder="Buscar produto pelo nome…" autocomplete="off">
      </div>
    </div>
    <!-- Category tabs -->
    <div class="cat-tabs" id="cat-tabs">
      <button class="cat-tab active" data-cat="all">Todos</button>
      <?php foreach($categories as $cat): ?>
        <button class="cat-tab" data-cat="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></button>
      <?php endforeach; ?>
    </div>
    <!-- Products grid -->
    <div class="products-grid" id="products-grid">
      <?php foreach($products as $p): ?>
        <div class="prod-card"
             data-id="<?= (int)$p['id'] ?>"
             data-name="<?= htmlspecialchars($p['nome']) ?>"
             data-price="<?= (float)$p['preco'] ?>"
             data-cat="<?= htmlspecialchars($p['categoria'] ?? '') ?>">
          <?php if($p['categoria']): ?>
            <span class="prod-cat-badge"><?= htmlspecialchars($p['categoria']) ?></span>
          <?php endif; ?>
          <span class="prod-name"><?= sanitize($p['nome']) ?></span>
          <span class="prod-price">R$ <?= number_format((float)$p['preco'],2,',','.') ?></span>
          <span class="prod-add-hint">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Adicionar
          </span>
        </div>
      <?php endforeach; ?>
      <?php if(empty($products)): ?>
        <div style="grid-column:1/-1;text-align:center;padding:3rem;color:#94a3b8;font-size:.875rem;">
          Nenhum produto cadastrado. <a href="products.php?action=create" style="color:#6366f1;">Cadastrar produto →</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── RIGHT: CARRINHO ── -->
  <div class="pos-cart">
    <!-- Header do carrinho -->
    <div class="cart-header">
      <h2>
        <span>Carrinho</span>
        <span class="cart-count" id="cart-count">0 itens</span>
      </h2>
      <!-- Canal de origem -->
      <div class="canal-tabs" id="canal-tabs">
        <button class="canal-tab active" data-canal="pdv">🏪 Loja</button>
        <button class="canal-tab" data-canal="whatsapp">💬 WhatsApp</button>
        <button class="canal-tab" data-canal="instagram">📷 Instagram</button>
        <button class="canal-tab" data-canal="online">🌐 Online</button>
      </div>
    </div>

    <!-- Histórico rápido do dia -->
    <?php if(!empty($todaySales)): ?>
    <div class="recent-sales">
      <p class="cart-section-label">Últimas vendas de hoje</p>
      <?php foreach($todaySales as $s): ?>
        <div class="rs-item">
          <span class="rs-name"><?= sanitize($s['cliente_nome'] ?: '#'.(int)$s['id']) ?></span>
          <span class="rs-val">R$ <?= number_format((float)$s['total'],2,',','.') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Cliente -->
    <div class="cart-client">
      <p class="cart-section-label">Cliente</p>
      <select id="client-select" class="pos-select">
        <option value="">— Selecionar cliente —</option>
        <?php foreach($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= sanitize($c['nome']) ?><?= $c['whatsapp'] ? ' · '.sanitize($c['whatsapp']) : '' ?></option>
        <?php endforeach; ?>
      </select>

      <div id="new-client-toggle" style="margin-top:.4rem;">
        <button type="button" id="btn-new-client" style="background:none;border:none;cursor:pointer;color:#6366f1;font-size:.75rem;font-weight:600;padding:0;font-family:'Outfit',sans-serif;">
          + Cadastrar novo cliente
        </button>
      </div>

      <div class="new-client-section" id="new-client-section">
        <div class="pos-input-row">
          <input id="new-client-name" class="pos-input" placeholder="Nome do cliente">
          <input id="new-client-phone" class="pos-input" placeholder="WhatsApp (DDD+nº)">
        </div>
      </div>

      <label class="no-client-check">
        <input type="checkbox" id="no-client-check">
        <span>Venda sem cadastro (cliente final)</span>
      </label>
    </div>

    <!-- Itens do carrinho -->
    <div class="cart-items" id="cart-items">
      <div class="cart-empty" id="cart-empty">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        <p>Clique nos produtos para adicionar</p>
      </div>
    </div>

    <!-- Footer do carrinho (totais + pagamento + finalizar) -->
    <div class="cart-footer">

      <!-- Totais -->
      <div class="cart-totals">
        <div class="cart-total-row">
          <span class="cart-total-label">Subtotal</span>
          <span class="cart-total-val" id="subtotal-display">R$ 0,00</span>
        </div>
        <div class="cart-total-row" id="desconto-total-row" style="display:none;">
          <span class="cart-total-label">Desconto</span>
          <span class="cart-total-val" id="desconto-display" style="color:#fbbf24;">− R$ 0,00</span>
        </div>
        <div class="cart-total-row grand">
          <span class="cart-total-label">Total</span>
          <span class="cart-total-val" id="total-display">R$ 0,00</span>
        </div>
      </div>

      <!-- Desconto -->
      <div class="desconto-row">
        <label style="color:#6b7280;font-size:.72rem;">Desconto R$</label>
        <input type="number" id="desconto-input" class="desconto-input" value="0" min="0" step="0.50" placeholder="0,00">
      </div>

      <!-- Formas de pagamento -->
      <p class="cart-section-label" style="margin-bottom:.4rem;">Pagamento</p>
      <div class="pagamento-grid" id="pagamento-grid">
        <button class="pag-btn active" data-pag="dinheiro">💵<span>Dinheiro</span></button>
        <button class="pag-btn" data-pag="pix">📱<span>Pix</span></button>
        <button class="pag-btn" data-pag="cartao_debito">💳<span>Débito</span></button>
        <button class="pag-btn" data-pag="cartao_credito">💳<span>Crédito</span></button>
      </div>

      <!-- Form oculto para submit -->
      <form method="POST" id="pos-form">
        <input type="hidden" name="client_id" id="f-client-id">
        <input type="hidden" name="new_client_name" id="f-new-client-name">
        <input type="hidden" name="new_client_phone" id="f-new-client-phone">
        <input type="hidden" name="no_client" id="f-no-client" value="">
        <input type="hidden" name="origem" id="f-origem" value="pdv">
        <input type="hidden" name="desconto" id="f-desconto" value="0">
        <input type="hidden" name="forma_pagamento" id="f-pagamento" value="dinheiro">
        <input type="hidden" name="items" id="f-items">
      </form>

      <button class="btn-finalizar" id="btn-finalizar">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Finalizar Venda
      </button>
      <button class="btn-limpar" id="btn-limpar" type="button">🗑 Limpar carrinho</button>
    </div>
  </div>

</div>

<!-- Toast de sucesso -->
<div class="pos-toast" id="pos-toast">
  <div class="toast-icon">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
  </div>
  <div>
    <p style="font-weight:700;color:#fff;font-size:.875rem;" id="toast-msg">Venda registrada!</p>
    <p style="color:#6b7280;font-size:.75rem;margin-top:.1rem;" id="toast-sub"></p>
  </div>
</div>

<script>
(function() {
  // ── Dados ──
  const products = <?= json_encode(array_values($products)) ?>;
  let cart = [];
  let activeCat = 'all';
  let activeCanal = 'pdv';
  let activePag = 'dinheiro';

  // ── Elementos ──
  const cartItemsEl  = document.getElementById('cart-items');
  const cartEmptyEl  = document.getElementById('cart-empty');
  const cartCountEl  = document.getElementById('cart-count');
  const subtotalEl   = document.getElementById('subtotal-display');
  const totalEl      = document.getElementById('total-display');
  const descontoEl   = document.getElementById('desconto-input');
  const descontoRow  = document.getElementById('desconto-total-row');
  const descontoDisp = document.getElementById('desconto-display');
  const searchEl     = document.getElementById('prod-search');
  const gridEl       = document.getElementById('products-grid');

  function fmt(v) {
    return 'R$ ' + (v||0).toFixed(2).replace('.',',');
  }

  // ── Render cart ──
  function renderCart() {
    // Remove itens antigos (preserva empty)
    Array.from(cartItemsEl.querySelectorAll('.cart-item')).forEach(el => el.remove());

    const subtotal = cart.reduce((s,it) => s + it.qty * it.price, 0);
    const desconto = Math.min(parseFloat(descontoEl.value)||0, subtotal);
    const total    = Math.max(0, subtotal - desconto);

    cartEmptyEl.style.display = cart.length === 0 ? 'flex' : 'none';
    cartCountEl.textContent   = cart.length + ' item' + (cart.length!==1?'s':'');
    subtotalEl.textContent    = fmt(subtotal);
    totalEl.textContent       = fmt(total);

    if (desconto > 0) {
      descontoRow.style.display = 'flex';
      descontoDisp.textContent  = '− ' + fmt(desconto);
    } else {
      descontoRow.style.display = 'none';
    }

    cart.forEach((it, idx) => {
      const div = document.createElement('div');
      div.className = 'cart-item';
      div.innerHTML = `
        <div class="ci-info">
          <p class="ci-name">${it.name}</p>
          <p class="ci-price">${fmt(it.price)} / un</p>
        </div>
        <div class="ci-qty-ctrl">
          <button class="ci-qty-btn" data-action="dec" data-idx="${idx}">−</button>
          <span class="ci-qty">${it.qty}</span>
          <button class="ci-qty-btn" data-action="inc" data-idx="${idx}">+</button>
        </div>
        <span class="ci-subtotal">${fmt(it.qty*it.price)}</span>
        <button class="ci-remove" data-idx="${idx}" title="Remover">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      `;
      cartItemsEl.insertBefore(div, cartEmptyEl);
    });
  }

  // ── Cart events ──
  cartItemsEl.addEventListener('click', e => {
    const btn = e.target.closest('[data-action],[data-idx]');
    if (!btn) return;
    const idx = parseInt(btn.dataset.idx, 10);
    const action = btn.dataset.action;
    if (action === 'inc') { cart[idx].qty++; }
    else if (action === 'dec') { if(cart[idx].qty > 1) cart[idx].qty--; else cart.splice(idx,1); }
    else if (btn.classList.contains('ci-remove')) { cart.splice(idx,1); }
    updateProductCards();
    renderCart();
  });

  descontoEl.addEventListener('input', renderCart);

  // ── Add product ──
  function addProduct(id, name, price) {
    const existing = cart.find(it => it.id === id);
    if (existing) { existing.qty++; }
    else { cart.push({ id, name, price, qty: 1 }); }
    updateProductCards();
    renderCart();
    // Feedback visual no card
    const card = gridEl.querySelector(`[data-id="${id}"]`);
    if (card) {
      card.classList.add('added');
      setTimeout(() => card.classList.remove('added'), 600);
    }
  }

  function updateProductCards() {
    const inCart = new Set(cart.map(it => it.id));
    gridEl.querySelectorAll('.prod-card').forEach(card => {
      const id = parseInt(card.dataset.id, 10);
      card.classList.toggle('added', inCart.has(id));
    });
  }

  // ── Product card click ──
  gridEl.addEventListener('click', e => {
    const card = e.target.closest('.prod-card');
    if (!card) return;
    addProduct(
      parseInt(card.dataset.id, 10),
      card.dataset.name,
      parseFloat(card.dataset.price)
    );
  });

  // ── Search ──
  searchEl.addEventListener('input', () => {
    const term = searchEl.value.trim().toLowerCase();
    gridEl.querySelectorAll('.prod-card').forEach(card => {
      const name = card.dataset.name.toLowerCase();
      const cat  = card.dataset.cat.toLowerCase();
      const matchCat  = activeCat === 'all' || cat === activeCat;
      const matchTerm = !term || name.includes(term);
      card.style.display = (matchCat && matchTerm) ? '' : 'none';
    });
  });

  // ── Category tabs ──
  document.getElementById('cat-tabs').addEventListener('click', e => {
    const tab = e.target.closest('.cat-tab');
    if (!tab) return;
    activeCat = tab.dataset.cat;
    document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const term = searchEl.value.trim().toLowerCase();
    gridEl.querySelectorAll('.prod-card').forEach(card => {
      const matchCat  = activeCat === 'all' || card.dataset.cat.toLowerCase() === activeCat;
      const matchTerm = !term || card.dataset.name.toLowerCase().includes(term);
      card.style.display = (matchCat && matchTerm) ? '' : 'none';
    });
  });

  // ── Canal tabs ──
  document.getElementById('canal-tabs').addEventListener('click', e => {
    const tab = e.target.closest('.canal-tab');
    if (!tab) return;
    activeCanal = tab.dataset.canal;
    document.querySelectorAll('.canal-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('f-origem').value = activeCanal;
  });

  // ── Pagamento ──
  document.getElementById('pagamento-grid').addEventListener('click', e => {
    const btn = e.target.closest('.pag-btn');
    if (!btn) return;
    activePag = btn.dataset.pag;
    document.querySelectorAll('.pag-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('f-pagamento').value = activePag;
  });

  // ── Novo cliente toggle ──
  document.getElementById('btn-new-client').addEventListener('click', () => {
    const sec = document.getElementById('new-client-section');
    sec.classList.toggle('visible');
  });

  // ── Sem cadastro ──
  const noCheck = document.getElementById('no-client-check');
  noCheck.addEventListener('change', () => {
    const disabled = noCheck.checked;
    document.getElementById('client-select').disabled = disabled;
    document.getElementById('btn-new-client').disabled = disabled;
    document.getElementById('new-client-name').disabled = disabled;
    document.getElementById('new-client-phone').disabled = disabled;
    [document.getElementById('client-select'), document.getElementById('btn-new-client')].forEach(el => {
      el.style.opacity = disabled ? '.4' : '1';
    });
  });

  // ── Limpar carrinho ──
  document.getElementById('btn-limpar').addEventListener('click', () => {
    if (cart.length === 0) return;
    if (confirm('Limpar todos os itens do carrinho?')) {
      cart = [];
      updateProductCards();
      renderCart();
    }
  });

  // ── Finalizar ──
  document.getElementById('btn-finalizar').addEventListener('click', () => {
    if (cart.length === 0) { alert('Adicione ao menos um produto.'); return; }

    const subtotal = cart.reduce((s,it) => s + it.qty*it.price, 0);
    const desconto = Math.min(parseFloat(descontoEl.value)||0, subtotal);

    document.getElementById('f-client-id').value     = document.getElementById('client-select').value;
    document.getElementById('f-new-client-name').value  = document.getElementById('new-client-name').value;
    document.getElementById('f-new-client-phone').value = document.getElementById('new-client-phone').value;
    document.getElementById('f-no-client').value     = noCheck.checked ? '1' : '';
    document.getElementById('f-desconto').value      = desconto;
    document.getElementById('f-items').value         = JSON.stringify(cart);

    document.getElementById('pos-form').submit();
  });

  // ── Toast de sucesso ──
  const flashMsg = document.getElementById('flash-success').value;
  if (flashMsg) {
    const toast   = document.getElementById('pos-toast');
    const toastMsg = document.getElementById('toast-msg');
    const toastSub = document.getElementById('toast-sub');
    const orderId  = document.getElementById('last-order-id').value;
    toastMsg.textContent = '✅ Venda registrada com sucesso!';
    toastSub.textContent = flashMsg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 4500);
    // Limpa o carrinho após venda
    cart = [];
    renderCart();
  }

  // ── Keyboard shortcut: F2 = foco na busca ──
  document.addEventListener('keydown', e => {
    if (e.key === 'F2') { e.preventDefault(); searchEl.focus(); searchEl.select(); }
    if (e.key === 'Escape') { searchEl.value = ''; searchEl.dispatchEvent(new Event('input')); }
  });

  renderCart();
})();
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>