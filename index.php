<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

$pdo = get_pdo();

if (function_exists('pdo_apply_timezone')) {
    pdo_apply_timezone($pdo, '+00:00');
}

$companyId = current_company_id();
if (!$companyId) {
    flash('error', 'Empresa não definida na sessão.');
    redirect('dashboard.php');
}

/* ── Intent parser ──────────────────────────────────────────────── */
function parse_resumo(?string $resumo): array {
    $default = ['text'=>'','intent'=>'outro','emoji'=>'💬','label'=>'Mensagem','confidence'=>0];
    if (!$resumo) return $default;
    $decoded = json_decode($resumo, true);
    if (!is_array($decoded)) return array_merge($default, ['label'=>$resumo,'text'=>$resumo]);

    $intent     = $decoded['intent']     ?? 'outro';
    $confidence = (float)($decoded['confidence'] ?? 0);

    $map = [
        'saudacao'          => ['label'=>'Saudação',            'emoji'=>'👋'],
        'interesse_produto' => ['label'=>'Interesse em produto', 'emoji'=>'🛍️'],
        'preco'             => ['label'=>'Consulta de preço',   'emoji'=>'💰'],
        'localizacao'       => ['label'=>'Localização',         'emoji'=>'📍'],
        'horario'           => ['label'=>'Horário',             'emoji'=>'🕐'],
        'agendamento'       => ['label'=>'Agendamento',         'emoji'=>'📅'],
        'pagamento'         => ['label'=>'Pagamento',           'emoji'=>'💳'],
        'reclamacao'        => ['label'=>'Reclamação',          'emoji'=>'⚠️'],
        'elogio'            => ['label'=>'Elogio',              'emoji'=>'⭐'],
        'despedida'         => ['label'=>'Despedida',           'emoji'=>'🤝'],
        'catalogo'          => ['label'=>'Catálogo',            'emoji'=>'👕'],
        'outro'             => ['label'=>'Mensagem',            'emoji'=>'💬'],
    ];
    $meta = $map[$intent] ?? $map['outro'];
    $pct  = $confidence > 0 ? ' · ' . round($confidence * 100) . '%' : '';
    return [
        'text'       => $meta['emoji'] . ' ' . $meta['label'] . $pct,
        'intent'     => $intent,
        'confidence' => $confidence,
        'emoji'      => $meta['emoji'],
        'label'      => $meta['label'],
    ];
}

function relative_time(?string $dt): string {
    if (!$dt) return '';
    $ts   = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 0)      $diff = 0;
    if ($diff < 60)     return 'agora';
    if ($diff < 3600)   return 'há ' . floor($diff / 60) . 'min';
    if ($diff < 86400)  return 'há ' . floor($diff / 3600) . 'h';
    if ($diff < 172800) return 'ontem';
    return date('d/m', $ts - 4 * 3600);
}

/* ── AJAX: retorna últimas interações como JSON ─────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'timeline') {
    header('Content-Type: application/json');
    $s = $pdo->prepare("
        SELECT i.id, i.created_at, i.titulo, i.resumo,
               c.nome AS cliente_nome, c.whatsapp AS cliente_whatsapp
        FROM interactions i
        JOIN clients c ON c.id = i.client_id
        WHERE i.company_id = ?
        ORDER BY i.created_at DESC
        LIMIT 20
    ");
    $s->execute([$companyId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);

    $palette = [
        ['#ede9fe','#6d28d9'],['#dcfce7','#15803d'],['#dbeafe','#1d4ed8'],
        ['#fef9c3','#a16207'],['#fce7f3','#be185d'],['#e0f2fe','#0369a1'],
    ];

    // Hoje KPI
    $kpi = $pdo->prepare("SELECT COUNT(*) FROM interactions WHERE company_id=? AND DATE(DATE_SUB(created_at,INTERVAL 4 HOUR))=CURDATE()");
    $kpi->execute([$companyId]);
    $hoje = (int)$kpi->fetchColumn();

    $items = [];
    $today     = date('Y-m-d', time() - 4*3600); // hoje em UTC-4
    $yesterday = date('Y-m-d', time() - 4*3600 - 86400);
    foreach ($rows as $ci => $ix) {
        $parsed = parse_resumo($ix['resumo'] ?? null);
        $ts     = strtotime($ix['created_at']) - (4 * 3600);
        $col    = $palette[$ci % count($palette)];
        $nome   = $ix['cliente_nome'] ?? '?';
        $dateKey= date('Y-m-d', $ts);
        $sepLabel = match($dateKey) {
            $today     => 'Hoje',
            $yesterday => 'Ontem',
            default    => date('d/m', $ts),
        };
        $items[] = [
            'id'       => $ix['id'],
            'nome'     => $nome,
            'initials' => mb_strtoupper(mb_substr($nome, 0, 1, 'UTF-8')),
            'titulo'   => $ix['titulo'] ?? 'Atendimento IA',
            'intent'   => $parsed['intent'],
            'label'    => $parsed['label'],
            'emoji'    => $parsed['emoji'],
            'hora'     => date('H:i', $ts),
            'dateKey'  => $dateKey,
            'sepLabel' => $sepLabel,
            'rel'      => relative_time($ix['created_at']),
            'ts'       => $ts,
            'avBg'     => $col[0],
            'avFg'     => $col[1],
        ];
    }
    // lastActivity = timestamp UTC da interação mais recente (para o banner de status)
    $lastActivity = !empty($rows) ? strtotime($rows[0]['created_at']) : null;
    echo json_encode(['items' => $items, 'hoje' => $hoje, 'lastActivity' => $lastActivity]);
    exit;
}

/* ── KPIs ─────────────────────────────────────────────────────── */
$s = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE company_id=?');
$s->execute([$companyId]); $clientsCount = (int)$s->fetchColumn();

$s = $pdo->prepare('SELECT COUNT(*) FROM products WHERE company_id=? AND ativo=1');
$s->execute([$companyId]); $productsCount = (int)$s->fetchColumn();

$s = $pdo->prepare("SELECT COUNT(*) FROM interactions WHERE company_id=? AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m')");
$s->execute([$companyId]); $interactionsCount = (int)$s->fetchColumn();

$s = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE company_id=? AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m')");
$s->execute([$companyId]); $ordersCount = (int)$s->fetchColumn();

$s = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE company_id=? AND origem='pdv' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m')");
$s->execute([$companyId]); $ordersPDV = (int)$s->fetchColumn();

$s = $pdo->prepare("SELECT COUNT(*) FROM interactions WHERE company_id=? AND DATE(DATE_SUB(created_at,INTERVAL 4 HOUR))=CURDATE()");
$s->execute([$companyId]); $interactionsHoje = (int)$s->fetchColumn();

/* ── Pedidos ──────────────────────────────────────────────────── */
$latestOrdersStmt = $pdo->prepare("
    SELECT o.*, c.nome AS cliente_nome
    FROM orders o
    LEFT JOIN clients c ON c.id = o.client_id
    WHERE o.company_id = ?
    ORDER BY o.created_at DESC
    LIMIT 6
");
$latestOrdersStmt->execute([$companyId]);
$latestOrders = $latestOrdersStmt->fetchAll();

include __DIR__ . '/views/partials/header.php';
?>

<style>
/* ── KPIs ── */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.85rem; margin-bottom:1.4rem; }
.kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1rem 1.25rem; position:relative; overflow:hidden; transition:box-shadow .15s; }
.kpi-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.06); }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--ac,#6366f1); border-radius:14px 14px 0 0; }
.kpi-lbl { font-size:.67rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:.4rem; }
.kpi-val { font-size:2rem; font-weight:800; color:#0f172a; line-height:1; transition:color .3s; }
.kpi-sub { font-size:.67rem; color:#94a3b8; margin-top:.3rem; }

/* ── Actions ── */
.act-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.85rem; margin-bottom:1.4rem; }
.act-btn { border-radius:14px; padding:1rem 1.25rem; color:#fff; text-decoration:none; display:flex; align-items:center; justify-content:space-between; transition:filter .15s,transform .1s; }
.act-btn:hover { filter:brightness(1.08); transform:translateY(-2px); }
.act-btn-title { font-size:.95rem; font-weight:700; }
.act-btn-sub   { font-size:.72rem; opacity:.8; margin-top:.1rem; }

/* ── Bottom ── */
.dash-bottom { display:grid; grid-template-columns:1fr 1fr; gap:1.1rem; }
.dp { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.dp-hd { padding:.85rem 1.25rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.dp-hd-left { display:flex; flex-direction:column; gap:.1rem; }
.dp-tag   { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; display:flex; align-items:center; gap:.35rem; }
.dp-title { font-size:.92rem; font-weight:700; color:#0f172a; }
.dp-link  { font-size:.72rem; color:#6366f1; font-weight:600; text-decoration:none; white-space:nowrap; }
.dp-link:hover { text-decoration:underline; }
.dp-body  { padding:.85rem 1rem; min-height:220px; }

/* ── Live dot ── */
.ldot { width:7px; height:7px; border-radius:50%; background:#22c55e; display:inline-block; flex-shrink:0; animation:ldot 1.6s ease-in-out infinite; }
@keyframes ldot { 0%,100%{opacity:1} 50%{opacity:.25} }

/* ── Refresh indicator ── */
.refresh-bar { display:flex; align-items:center; gap:.5rem; font-size:.65rem; color:#94a3b8; }
.refresh-ring { width:12px; height:12px; border:1.5px solid #e2e8f0; border-top-color:#6366f1; border-radius:50%; display:none; animation:spin .7s linear infinite; }
.refresh-ring.spinning { display:inline-block; }
@keyframes spin { to{transform:rotate(360deg)} }

/* ── Timeline ── */
.tl { display:flex; flex-direction:column; }
.tl-sep { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#cbd5e1; padding:.5rem 0 .3rem; display:flex; align-items:center; gap:.5rem; }
.tl-sep::after { content:''; flex:1; height:1px; background:#f1f5f9; }

.tl-row { display:flex; align-items:center; gap:.7rem; padding:.6rem .4rem; border-radius:10px; transition:background .1s; animation:fadeIn .3s ease; }
.tl-row:hover { background:#f8fafc; }
@keyframes fadeIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:translateY(0)} }

.tl-av { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:800; flex-shrink:0; border:2px solid #fff; }
.tl-info { flex:1; min-width:0; }
.tl-name { font-size:.8rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; }
.tl-badge { display:inline-flex; align-items:center; gap:.2rem; font-size:.62rem; font-weight:600; padding:.12rem .4rem; border-radius:20px; background:#f1f5f9; color:#64748b; white-space:nowrap; }
.tl-badge.saudacao          { background:#ede9fe; color:#6d28d9; }
.tl-badge.interesse_produto { background:#dcfce7; color:#15803d; }
.tl-badge.preco             { background:#fef9c3; color:#a16207; }
.tl-badge.agendamento       { background:#dbeafe; color:#1d4ed8; }
.tl-badge.pagamento         { background:#e0f2fe; color:#0369a1; }
.tl-badge.reclamacao        { background:#fee2e2; color:#dc2626; }
.tl-badge.elogio            { background:#fef9c3; color:#a16207; }
.tl-badge.catalogo          { background:#dcfce7; color:#15803d; }
.tl-titulo { font-size:.72rem; color:#94a3b8; margin-top:.05rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tl-time { text-align:right; flex-shrink:0; min-width:38px; }
.tl-time-h   { font-size:.72rem; font-weight:600; color:#475569; }
.tl-time-rel { font-size:.6rem; color:#cbd5e1; margin-top:.1rem; }

/* ── Orders ── */
.ord-row { display:flex; align-items:center; gap:.7rem; padding:.6rem .4rem; border-radius:10px; transition:background .1s; }
.ord-row:hover { background:#f8fafc; }
.ord-ico  { width:32px; height:32px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; }
.ord-info { flex:1; min-width:0; }
.ord-name { font-size:.8rem; font-weight:700; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ord-tags { display:flex; gap:.25rem; margin-top:.2rem; flex-wrap:wrap; }
.ob { display:inline-flex; font-size:.62rem; font-weight:700; padding:.1rem .4rem; border-radius:20px; }
.ob.pdv,   .ob.concluido { background:#dcfce7; color:#15803d; }
.ob.online,.ob.pago      { background:#dbeafe; color:#1d4ed8; }
.ob.manual,.ob.pendente  { background:#fef9c3; color:#a16207; }
.ob.cancelado            { background:#fee2e2; color:#dc2626; }
.ob.novo                 { background:#ede9fe; color:#6d28d9; }
.ord-val { text-align:right; flex-shrink:0; }
.ord-price { font-size:.8rem; font-weight:700; color:#0f172a; }
.ord-time  { font-size:.6rem; color:#cbd5e1; }

.empty-tl { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:2rem 1rem; color:#94a3b8; gap:.35rem; }
.empty-tl span { font-size:1.6rem; }
.empty-tl p { font-size:.78rem; }
</style>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi-card" style="--ac:#6366f1;">
    <p class="kpi-lbl">Clientes</p>
    <p class="kpi-val" id="k-clientes"><?= $clientsCount ?></p>
    <p class="kpi-sub">cadastrados</p>
  </div>
  <div class="kpi-card" style="--ac:#0ea5e9;">
    <p class="kpi-lbl">Produtos ativos</p>
    <p class="kpi-val"><?= $productsCount ?></p>
    <p class="kpi-sub">no catálogo</p>
  </div>
  <div class="kpi-card" style="--ac:#22c55e;">
    <p class="kpi-lbl">Atendimentos hoje</p>
    <p class="kpi-val" id="k-hoje"><?= $interactionsHoje ?></p>
    <p class="kpi-sub" id="k-mes"><?= $interactionsCount ?> no mês</p>
  </div>
  <div class="kpi-card" style="--ac:#f59e0b;">
    <p class="kpi-lbl">Pedidos no mês</p>
    <p class="kpi-val"><?= $ordersCount ?></p>
    <p class="kpi-sub"><?= $ordersPDV ?> via PDV</p>
  </div>
  <div class="kpi-card" style="--ac:#8b5cf6;">
    <p class="kpi-lbl">Vendas PDV mês</p>
    <p class="kpi-val"><?= $ordersPDV ?></p>
    <p class="kpi-sub">no caixa</p>
  </div>
</div>

<!-- Ações rápidas -->
<div class="act-grid">
  <a href="<?= BASE_URL ?>/clients.php?action=create" class="act-btn" style="background:linear-gradient(135deg,#6366f1,#4f46e5);">
    <div><p class="act-btn-title">Novo Cliente</p><p class="act-btn-sub">Cadastre um contato</p></div>
    <span style="font-size:1.2rem;opacity:.7;">→</span>
  </a>
  <a href="<?= BASE_URL ?>/products.php?action=create" class="act-btn" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);">
    <div><p class="act-btn-title">Novo Produto</p><p class="act-btn-sub">Produtos ou serviços</p></div>
    <span style="font-size:1.2rem;opacity:.7;">→</span>
  </a>
  <a href="<?= BASE_URL ?>/promotions.php?action=create" class="act-btn" style="background:linear-gradient(135deg,#22c55e,#16a34a);">
    <div><p class="act-btn-title">Criar Promoção</p><p class="act-btn-sub">Landing page rápida</p></div>
    <span style="font-size:1.2rem;opacity:.7;">→</span>
  </a>
</div>

<!-- Timeline + Pedidos -->
<div class="dash-bottom">

  <!-- TIMELINE -->
  <div class="dp">
    <div class="dp-hd">
      <div class="dp-hd-left">
        <span class="dp-tag">
          <span class="ldot" id="live-dot"></span> Ao vivo &middot; WhatsApp
        </span>
        <span class="dp-title">Timeline de Atendimentos</span>
      </div>
      <div style="display:flex;align-items:center;gap:.75rem;">
        <div class="refresh-bar">
          <div class="refresh-ring" id="refresh-ring"></div>
          <span id="refresh-countdown" style="font-variant-numeric:tabular-nums;">30s</span>
        </div>
        <a class="dp-link" href="<?= BASE_URL ?>/atendimento.php">Ver todos →</a>
      </div>
    </div>
    <!-- Banner de status do webhook -->
    <div id="webhook-status" style="padding:.4rem 1.25rem;font-size:.68rem;background:#f8fafc;border-bottom:1px solid #f1f5f9;color:#64748b;">
      ⏳ Verificando conexão...
    </div>
    <div class="dp-body">
      <div class="tl" id="tl-container">
        <!-- Populado pelo JS via AJAX -->
        <div class="empty-tl"><span>⏳</span><p>Carregando...</p></div>
      </div>
    </div>
  </div>

  <!-- PEDIDOS -->
  <div class="dp">
    <div class="dp-hd">
      <div class="dp-hd-left">
        <span class="dp-tag">Últimos registros</span>
        <span class="dp-title">Pedidos Recentes</span>
      </div>
      <a class="dp-link" href="<?= BASE_URL ?>/orders.php">Ver todos →</a>
    </div>
    <div class="dp-body">
      <?php if (empty($latestOrders)): ?>
        <div class="empty-tl"><span>🧾</span><p>Nenhum pedido registrado</p></div>
      <?php else: ?>
        <?php foreach ($latestOrders as $order):
            $ts          = strtotime($order['created_at']) - (4 * 3600);
            $hora        = date('H:i', $ts);
            $origem      = strtolower($order['origem'] ?? '');
            $origemClass = in_array($origem,['pdv','online','loja']) ? $origem : 'manual';
            $status      = strtolower($order['status'] ?? '');
            $statusClass = match(true) {
                in_array($status,['concluido','pago','finalizado']) => 'concluido',
                $status === 'cancelado' => 'cancelado',
                $status === 'novo'      => 'novo',
                default => 'pendente',
            };
        ?>
          <div class="ord-row">
            <div class="ord-ico">🧾</div>
            <div class="ord-info">
              <div class="ord-name"><?= sanitize($order['cliente_nome'] ?? 'Não identificado') ?></div>
              <div class="ord-tags">
                <span class="ob <?= $origemClass ?>"><?= strtoupper($order['origem'] ?? '—') ?></span>
                <span class="ob <?= $statusClass ?>"><?= sanitize($order['status'] ?? '—') ?></span>
              </div>
            </div>
            <div class="ord-val">
              <div class="ord-price"><?= format_currency($order['total'] ?? 0) ?></div>
              <div class="ord-time"><?= $hora ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// ── Paleta de avatares ──
const PALETTE = [
  ['#ede9fe','#6d28d9'],['#dcfce7','#15803d'],['#dbeafe','#1d4ed8'],
  ['#fef9c3','#a16207'],['#fce7f3','#be185d'],['#e0f2fe','#0369a1'],
];

let lastTopId    = null;
let countdown    = 30;
let countdownTmr = null;

// ── Renderiza a timeline a partir dos dados da API ──
function renderTimeline(items, lastActivity) {
  const container = document.getElementById('tl-container');
  if (!items || items.length === 0) {
    container.innerHTML = '<div class="empty-tl"><span>💬</span><p>Nenhum atendimento registrado</p></div>';
    return;
  }

  // Banner de status do webhook — usa lastActivity vindo do servidor
  const banner = document.getElementById('webhook-status');
  if (banner && lastActivity) {
    const diffMin = Math.floor((Date.now()/1000 - lastActivity) / 60);
    if (diffMin < 60) {
      banner.innerHTML = `<span style="color:#16a34a;">🟢 Conectado</span> · última msg há ${diffMin < 1 ? 'menos de 1' : diffMin} min`;
      banner.style.color = '#64748b';
    } else if (diffMin < 1440) {
      const hrs = Math.floor(diffMin/60);
      banner.innerHTML = `<span style="color:#d97706;">🟡 Inativo</span> · última msg há ${hrs}h — verifique o ActivePieces`;
      banner.style.color = '#92400e';
    } else {
      const days = Math.floor(diffMin/1440);
      banner.innerHTML = `<span style="color:#dc2626;">🔴 Desconectado</span> · sem atividade há ${days} dia(s)`;
      banner.style.color = '#991b1b';
    }
  }

  let html = '<div class="tl">';
  let lastDateKey = null;

  items.forEach((ix, ci) => {
    const sep = ix.dateKey !== lastDateKey;
    lastDateKey = ix.dateKey;
    // sepLabel já vem do servidor corretamente em PT-BR (Hoje/Ontem/DD/MM)
    const col = PALETTE[ci % PALETTE.length];
    const badgeClass = ix.intent && ix.intent !== 'outro' ? ix.intent : '';
    const badge = badgeClass
      ? `<span class="tl-badge ${badgeClass}">${ix.emoji} ${ix.label}</span>`
      : '';

    html += sep ? `<div class="tl-sep">${ix.sepLabel}</div>` : '';
    html += `
      <div class="tl-row">
        <div class="tl-av" style="background:${col[0]};color:${col[1]};box-shadow:0 0 0 1.5px ${col[0]};">${ix.initials}</div>
        <div class="tl-info">
          <div class="tl-name">${escHtml(ix.nome)} ${badge}</div>
          <div class="tl-titulo">${escHtml(ix.titulo)}</div>
        </div>
        <div class="tl-time">
          <div class="tl-time-h">${ix.hora}</div>
          <div class="tl-time-rel">${ix.rel}</div>
        </div>
      </div>`;
  });

  html += '</div>';
  container.innerHTML = html;
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Busca timeline via AJAX ──
async function fetchTimeline(forceFlash) {
  const ring = document.getElementById('refresh-ring');
  ring.classList.add('spinning');

  try {
    const res  = await fetch('?ajax=timeline&_=' + Date.now());
    const data = await res.json();
    const items = data.items || [];

    // Pisca a ldot verde se chegou item novo
    const newTopId = items[0]?.id ?? null;
    if (forceFlash || (lastTopId !== null && newTopId !== lastTopId)) {
      flashDot();
      // Atualiza KPI "hoje"
      const k = document.getElementById('k-hoje');
      if (k && data.hoje !== undefined) {
        k.textContent = data.hoje;
        k.style.color = '#22c55e';
        setTimeout(() => k.style.color = '', 1200);
      }
    }
    lastTopId = newTopId;
    renderTimeline(items, data.lastActivity ?? null);
  } catch(e) {
    console.warn('Timeline fetch error:', e);
  } finally {
    ring.classList.remove('spinning');
  }
}

// ── Pisca o dot ao vivo ──
function flashDot() {
  const dot = document.getElementById('live-dot');
  dot.style.background = '#6366f1';
  dot.style.transform = 'scale(1.6)';
  setTimeout(() => {
    dot.style.background = '#22c55e';
    dot.style.transform  = '';
  }, 800);
}

// ── Contagem regressiva + auto-refresh (5 minutos) ──
const REFRESH_SEC = 300; // 5 minutos

function startCountdown() {
  const el = document.getElementById('refresh-countdown');
  clearInterval(countdownTmr);
  countdown = REFRESH_SEC;

  function fmt(s) {
    if (s >= 60) return Math.ceil(s/60) + 'min';
    return s + 's';
  }

  if (el) el.textContent = fmt(countdown);

  countdownTmr = setInterval(() => {
    countdown--;
    if (el) el.textContent = fmt(countdown);
    if (countdown <= 0) {
      countdown = REFRESH_SEC;
      if (el) el.textContent = fmt(countdown);
      fetchTimeline(false);
    }
  }, 1000);
}

// ── Init ──
document.addEventListener('DOMContentLoaded', () => {
  fetchTimeline(true); // carrega imediato
  startCountdown();    // auto-refresh a cada 5 min
});
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>