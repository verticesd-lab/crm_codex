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

function safe_dt(?string $dt): string {
    if (!$dt) return '';
    if (function_exists('format_datetime_br')) return format_datetime_br($dt);
    return date('d/m H:i', strtotime($dt));
}

/**
 * Converte resumo (JSON de intent ou texto) em label legível
 */
function parse_resumo(?string $resumo): array {
    if (!$resumo) return ['text'=>'','intent'=>'outro','emoji'=>'💬','label'=>'Mensagem'];
    $decoded = json_decode($resumo, true);
    if (!is_array($decoded)) return ['text'=>$resumo,'intent'=>'outro','emoji'=>'💬','label'=>$resumo];

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

/**
 * Tempo relativo (ex: "há 5 min", "há 2h", "ontem")
 * Assume DB em UTC, app em UTC-4 (Cuiabá)
 */
function relative_time(?string $dt): string {
    if (!$dt) return '';
    $ts   = strtotime($dt);
    $now  = time();
    $diff = $now - $ts;            // diferença em segundos (UTC vs UTC)
    if ($diff < 0)      $diff = 0;
    if ($diff < 60)     return 'agora';
    if ($diff < 3600)   return 'há ' . floor($diff / 60) . ' min';
    if ($diff < 86400)  return 'há ' . floor($diff / 3600) . 'h';
    if ($diff < 172800) return 'ontem';
    return date('d/m', $ts - 4 * 3600);
}

// ── KPIs ──────────────────────────────────────────────────────────
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

// Atendimentos só hoje (UTC-4 = Cuiabá)
$s = $pdo->prepare("SELECT COUNT(*) FROM interactions WHERE company_id=? AND DATE(DATE_SUB(created_at, INTERVAL 4 HOUR))=CURDATE()");
$s->execute([$companyId]); $interactionsHoje = (int)$s->fetchColumn();

// ── Últimas 6 interações ───────────────────────────────────────────
$latestInteractionsStmt = $pdo->prepare("
    SELECT i.*, c.nome AS cliente_nome, c.whatsapp AS cliente_whatsapp
    FROM interactions i
    JOIN clients c ON c.id = i.client_id
    WHERE i.company_id = ?
    ORDER BY i.created_at DESC
    LIMIT 6
");
$latestInteractionsStmt->execute([$companyId]);
$latestInteractions = $latestInteractionsStmt->fetchAll();

// ── Últimos 6 pedidos ──────────────────────────────────────────────
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
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(155px,1fr)); gap:.85rem; margin-bottom:1.4rem; }
.kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1rem 1.2rem; position:relative; overflow:hidden; }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--ac,#6366f1); border-radius:14px 14px 0 0; }
.kpi-lbl { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-bottom:.35rem; }
.kpi-val { font-size:2rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-sub { font-size:.67rem; color:#94a3b8; margin-top:.3rem; }

/* ── Actions ── */
.act-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.85rem; margin-bottom:1.4rem; }
.act-btn  { border-radius:14px; padding:1rem 1.25rem; color:#fff; text-decoration:none;
            display:flex; align-items:center; justify-content:space-between;
            transition:filter .15s,transform .1s; }
.act-btn:hover { filter:brightness(1.08); transform:translateY(-1px); }
.act-btn-title { font-size:.95rem; font-weight:700; }
.act-btn-sub   { font-size:.72rem; opacity:.8; margin-top:.1rem; }

/* ── Bottom grid ── */
.dash-bottom { display:grid; grid-template-columns:1fr 1fr; gap:1.1rem; }
.dp { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.dp-hd { padding:.8rem 1.2rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.dp-hd-left { display:flex; flex-direction:column; gap:.1rem; }
.dp-tag   { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; display:flex; align-items:center; gap:.35rem; }
.dp-title { font-size:.92rem; font-weight:700; color:#0f172a; }
.dp-link  { font-size:.72rem; color:#6366f1; font-weight:600; text-decoration:none; }
.dp-link:hover { text-decoration:underline; }
.dp-body  { padding:.75rem 1rem; }

/* ── Live dot ── */
.ldot { width:7px; height:7px; border-radius:50%; background:#22c55e;
        display:inline-block; flex-shrink:0;
        animation:ldot-pulse 1.6s ease-in-out infinite; }
@keyframes ldot-pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Timeline ── */
.tl { display:flex; flex-direction:column; }
.tl-sep { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em;
          color:#cbd5e1; padding:.5rem 0 .3rem; display:flex; align-items:center; gap:.5rem; }
.tl-sep::after { content:''; flex:1; height:1px; background:#f1f5f9; }

.tl-row { display:flex; align-items:center; gap:.7rem; padding:.55rem .35rem; border-radius:10px; transition:background .1s; }
.tl-row:hover { background:#f8fafc; }

.tl-av { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center;
         font-size:.78rem; font-weight:800; flex-shrink:0;
         background:var(--bg,#ede9fe); color:var(--fg,#6d28d9);
         border:2px solid #fff; box-shadow:0 0 0 1.5px var(--bg,#ede9fe); }

.tl-info { flex:1; min-width:0; }
.tl-name { font-size:.8rem; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; }
.tl-badge { display:inline-flex; align-items:center; gap:.2rem; font-size:.63rem; font-weight:600;
            padding:.12rem .4rem; border-radius:20px; background:#f1f5f9; color:#64748b; white-space:nowrap; }
.tl-badge.saudacao          { background:#ede9fe; color:#6d28d9; }
.tl-badge.interesse_produto { background:#dcfce7; color:#15803d; }
.tl-badge.preco             { background:#fef9c3; color:#a16207; }
.tl-badge.agendamento       { background:#dbeafe; color:#1d4ed8; }
.tl-badge.pagamento         { background:#e0f2fe; color:#0369a1; }
.tl-badge.reclamacao        { background:#fee2e2; color:#dc2626; }
.tl-badge.elogio            { background:#fef9c3; color:#a16207; }
.tl-badge.despedida         { background:#f1f5f9; color:#64748b; }
.tl-titulo { font-size:.72rem; color:#94a3b8; margin-top:.1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.tl-time { text-align:right; flex-shrink:0; }
.tl-time-main { font-size:.72rem; font-weight:600; color:#475569; }
.tl-time-rel  { font-size:.6rem; color:#cbd5e1; margin-top:.1rem; }

/* ── Orders ── */
.ord-row { display:flex; align-items:center; gap:.7rem; padding:.55rem .35rem; border-radius:10px; transition:background .1s; }
.ord-row:hover { background:#f8fafc; }
.ord-ico { width:32px; height:32px; border-radius:50%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:.9rem; flex-shrink:0; }
.ord-info { flex:1; min-width:0; }
.ord-name  { font-size:.8rem; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ord-tags  { display:flex; gap:.25rem; margin-top:.2rem; flex-wrap:wrap; }
.obadge { display:inline-flex; font-size:.62rem; font-weight:700; padding:.1rem .4rem; border-radius:20px; }
.obadge.pdv       { background:#dcfce7; color:#15803d; }
.obadge.online    { background:#dbeafe; color:#1d4ed8; }
.obadge.manual    { background:#f1f5f9; color:#64748b; }
.obadge.pendente  { background:#fef9c3; color:#a16207; }
.obadge.concluido { background:#dcfce7; color:#15803d; }
.obadge.cancelado { background:#fee2e2; color:#dc2626; }

.ord-val { text-align:right; flex-shrink:0; }
.ord-price { font-size:.8rem; font-weight:700; color:#0f172a; }
.ord-time  { font-size:.6rem; color:#cbd5e1; }

/* Empty */
.empty-tl { display:flex; flex-direction:column; align-items:center; padding:1.5rem 1rem; color:#94a3b8; gap:.35rem; }
.empty-tl span { font-size:1.6rem; }
.empty-tl p { font-size:.78rem; }
</style>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi-card" style="--ac:#6366f1;">
    <p class="kpi-lbl">Clientes</p>
    <p class="kpi-val"><?= $clientsCount ?></p>
    <p class="kpi-sub">cadastrados</p>
  </div>
  <div class="kpi-card" style="--ac:#0ea5e9;">
    <p class="kpi-lbl">Produtos ativos</p>
    <p class="kpi-val"><?= $productsCount ?></p>
    <p class="kpi-sub">no catálogo</p>
  </div>
  <div class="kpi-card" style="--ac:#22c55e;">
    <p class="kpi-lbl">Atendimentos hoje</p>
    <p class="kpi-val"><?= $interactionsHoje ?></p>
    <p class="kpi-sub"><?= $interactionsCount ?> no mês</p>
  </div>
  <div class="kpi-card" style="--ac:#f59e0b;">
    <p class="kpi-lbl">Pedidos no mês</p>
    <p class="kpi-val"><?= $ordersCount ?></p>
    <p class="kpi-sub"><?= $ordersPDV ?> via PDV</p>
  </div>
  <div class="kpi-card" style="--ac:#8b5cf6;">
    <p class="kpi-lbl">Vendas PDV mês</p>
    <p class="kpi-val"><?= $ordersPDV ?></p>
    <p class="kpi-sub">registradas no caixa</p>
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

  <!-- TIMELINE WHATSAPP -->
  <div class="dp">
    <div class="dp-hd">
      <div class="dp-hd-left">
        <span class="dp-tag"><span class="ldot"></span> Ao vivo &middot; WhatsApp</span>
        <span class="dp-title">Timeline de Atendimentos</span>
      </div>
      <a class="dp-link" href="<?= BASE_URL ?>/atendimento.php">Ver todos →</a>
    </div>
    <div class="dp-body">
      <?php if (empty($latestInteractions)): ?>
        <div class="empty-tl"><span>💬</span><p>Nenhum atendimento registrado</p></div>
      <?php else: ?>
        <?php
        // Paleta de cores para avatares
        $palette = [
            ['#ede9fe','#6d28d9'], ['#dcfce7','#15803d'], ['#dbeafe','#1d4ed8'],
            ['#fef9c3','#a16207'], ['#fce7f3','#be185d'], ['#e0f2fe','#0369a1'],
        ];
        $lastDate = null; $ci = 0;
        ?>
        <div class="tl">
        <?php foreach ($latestInteractions as $ix):
            // Converte UTC → UTC-4 para exibição
            $ts       = strtotime($ix['created_at']) - (4 * 3600);
            $dateKey  = date('Y-m-d', $ts);
            $today    = date('Y-m-d');
            $yesterday= date('Y-m-d', time() - 86400);
            $sepLabel = match($dateKey) {
                $today     => 'Hoje',
                $yesterday => 'Ontem',
                default    => date('d/m', $ts),
            };
            $parsed   = parse_resumo($ix['resumo'] ?? null);
            $titulo   = $ix['titulo'] ?? 'Atendimento IA';
            $nome     = $ix['cliente_nome'] ?? '?';
            $initials = mb_strtoupper(mb_substr($nome, 0, 1, 'UTF-8'));
            $col      = $palette[$ci % count($palette)]; $ci++;
            $hora     = date('H:i', $ts);
            $rel      = relative_time($ix['created_at']);
        ?>
          <?php if ($dateKey !== $lastDate): $lastDate = $dateKey; ?>
            <div class="tl-sep"><?= $sepLabel ?></div>
          <?php endif; ?>

          <div class="tl-row">
            <div class="tl-av" style="--bg:<?= $col[0] ?>;--fg:<?= $col[1] ?>;"><?= sanitize($initials) ?></div>
            <div class="tl-info">
              <div class="tl-name">
                <?= sanitize($nome) ?>
                <?php if ($parsed['intent'] && $parsed['intent'] !== 'outro'): ?>
                  <span class="tl-badge <?= htmlspecialchars($parsed['intent']) ?>"><?= $parsed['emoji'] ?> <?= $parsed['label'] ?></span>
                <?php endif; ?>
              </div>
              <div class="tl-titulo"><?= sanitize($titulo) ?></div>
            </div>
            <div class="tl-time">
              <div class="tl-time-main"><?= $hora ?></div>
              <div class="tl-time-rel"><?= $rel ?></div>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
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
            $ts  = strtotime($order['created_at']) - (4 * 3600);
            $hora = date('H:i', $ts);
            $origem = strtolower($order['origem'] ?? '');
            $origemClass = in_array($origem,['pdv','online']) ? $origem : 'manual';
            $origemLabel = strtoupper($order['origem'] ?? '—');
            $status = strtolower($order['status'] ?? '');
            $statusClass = match(true) {
                in_array($status,['concluido','pago','finalizado']) => 'concluido',
                $status === 'cancelado' => 'cancelado',
                default => 'pendente',
            };
        ?>
          <div class="ord-row">
            <div class="ord-ico">🧾</div>
            <div class="ord-info">
              <div class="ord-name"><?= sanitize($order['cliente_nome'] ?? 'Não identificado') ?></div>
              <div class="ord-tags">
                <span class="obadge <?= $origemClass ?>"><?= $origemLabel ?></span>
                <span class="obadge <?= $statusClass ?>"><?= sanitize($order['status'] ?? '—') ?></span>
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

<?php include __DIR__ . '/views/partials/footer.php'; ?>