<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
$agendaHelpersFile = __DIR__ . '/agenda_helpers.php';
if (is_file($agendaHelpersFile)) require_once $agendaHelpersFile;
require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

/* ══════════════════════════════════════════════════
   FILTRO DE PERÍODO
══════════════════════════════════════════════════ */
$period    = $_GET['period']   ?? 'mes';
$dateFrom  = $_GET['date_from']?? '';
$dateTo    = $_GET['date_to']  ?? '';

switch ($period) {
    case 'hoje':
        $dFrom = date('Y-m-d');
        $dTo   = date('Y-m-d');
        $label = 'Hoje';
        break;
    case '7d':
        $dFrom = date('Y-m-d', strtotime('-6 days'));
        $dTo   = date('Y-m-d');
        $label = 'Últimos 7 dias';
        break;
    case '30d':
        $dFrom = date('Y-m-d', strtotime('-29 days'));
        $dTo   = date('Y-m-d');
        $label = 'Últimos 30 dias';
        break;
    case 'mes':
        $dFrom = date('Y-m-01');
        $dTo   = date('Y-m-d');
        $label = 'Mês atual (' . ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'][(int)date('m')] . ')';
        break;
    case 'ano':
        $dFrom = date('Y-01-01');
        $dTo   = date('Y-m-d');
        $label = 'Ano atual (' . date('Y') . ')';
        break;
    case 'custom':
        $dFrom = $dateFrom ?: date('Y-m-01');
        $dTo   = $dateTo   ?: date('Y-m-d');
        $label = date('d/m/Y', strtotime($dFrom)) . ' → ' . date('d/m/Y', strtotime($dTo));
        break;
    default:
        $dFrom = date('Y-m-01');
        $dTo   = date('Y-m-d');
        $label = 'Mês atual';
}

/* Período anterior para comparação */
$diffDays = max(1, (int)((strtotime($dTo) - strtotime($dFrom)) / 86400) + 1);
$prevFrom = date('Y-m-d', strtotime($dFrom) - $diffDays * 86400);
$prevTo   = date('Y-m-d', strtotime($dFrom) - 86400);

/* Helper de trend */
function trend(float $atual, float $ant): array {
    if ($ant == 0) return ['pct'=>0,'dir'=>'flat','label'=>'—','color'=>'#94a3b8'];
    $p = round(($atual - $ant) / $ant * 100, 1);
    return [
        'pct'   => $p,
        'dir'   => $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat'),
        'label' => ($p > 0 ? '↑ +' : ($p < 0 ? '↓ ' : '→ ')) . abs($p) . '%',
        'color' => $p > 0 ? '#16a34a' : ($p < 0 ? '#dc2626' : '#94a3b8'),
    ];
}
function qn(PDO $pdo, string $sql, array $p): float {
    $s = $pdo->prepare($sql); $s->execute($p); return (float)($s->fetchColumn() ?: 0);
}
function has_col(array $cols, string $col): bool {
    return in_array($col, $cols, true);
}
function sql_col(string $col, string $alias = ''): string {
    $quoted = '`' . str_replace('`', '``', $col) . '`';
    return $alias !== '' ? $alias . '.' . $quoted : $quoted;
}

/* ══════════════════════════════════════════════════
   BLOCO 1 — LOJA (clientes, produtos, pedidos)
══════════════════════════════════════════════════ */
// Clientes
$totalClientes     = (int)qn($pdo,"SELECT COUNT(*) FROM clients WHERE company_id=?",[$companyId]);
$clientesNovos     = (int)qn($pdo,"SELECT COUNT(*) FROM clients WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);
$clientesNovosAnt  = (int)qn($pdo,"SELECT COUNT(*) FROM clients WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$prevFrom,$prevTo]);
$tClientes         = trend($clientesNovos, $clientesNovosAnt);

// Atendimentos WhatsApp / IA
$atend     = (int)qn($pdo,"SELECT COUNT(*) FROM interactions WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);
$atendAnt  = (int)qn($pdo,"SELECT COUNT(*) FROM interactions WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$prevFrom,$prevTo]);
$tAtend    = trend($atend, $atendAnt);

// Pedidos / Vendas
$pedidos      = (int)qn($pdo,"SELECT COUNT(*) FROM orders WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);
$pedidosAnt   = (int)qn($pdo,"SELECT COUNT(*) FROM orders WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$prevFrom,$prevTo]);
$tPedidos     = trend($pedidos, $pedidosAnt);

$faturamento     = qn($pdo,"SELECT SUM(total) FROM orders WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);
$faturamentoAnt  = qn($pdo,"SELECT SUM(total) FROM orders WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$prevFrom,$prevTo]);
$tFat            = trend($faturamento, $faturamentoAnt);

$pdv     = (int)qn($pdo,"SELECT COUNT(*) FROM orders WHERE company_id=? AND origem='pdv' AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);
$pdvFat  = qn($pdo,"SELECT SUM(total) FROM orders WHERE company_id=? AND origem='pdv' AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);

// Produtos
$prodAtivos   = (int)qn($pdo,"SELECT COUNT(*) FROM products WHERE company_id=? AND ativo=1",[$companyId]);
$prodTotal    = (int)qn($pdo,"SELECT COUNT(*) FROM products WHERE company_id=?",[$companyId]);
$prodCadast   = (int)qn($pdo,"SELECT COUNT(*) FROM products WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);
$prodRemovidos= $prodTotal - $prodAtivos;

// Top produtos
$topProd = $pdo->prepare("SELECT p.nome, SUM(oi.quantidade) as qtd, SUM(oi.subtotal) as valor
    FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN products p ON p.id=oi.product_id
    WHERE o.company_id=? AND DATE(o.created_at) BETWEEN ? AND ?
    GROUP BY p.id ORDER BY valor DESC LIMIT 8");
$topProd->execute([$companyId,$dFrom,$dTo]);
$topProd = $topProd->fetchAll(PDO::FETCH_ASSOC);

// Por origem
$origens = $pdo->prepare("SELECT origem, COUNT(*) as total, SUM(total) as soma FROM orders WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY origem");
$origens->execute([$companyId,$dFrom,$dTo]);
$origens = $origens->fetchAll(PDO::FETCH_ASSOC);

/* ══════════════════════════════════════════════════
   BLOCO 2 — BARBEARIA (agenda)
══════════════════════════════════════════════════ */
$apptCols = [];
try { $apptCols = $pdo->query('SHOW COLUMNS FROM appointments')->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable $e){}
$hasAppt = !empty($apptCols);
$barberCols = [];
try { $barberCols = $pdo->query('SHOW COLUMNS FROM barbers')->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable $e){}
$hasBarbers = !empty($barberCols);

$agendaStats  = [];
$barbeirStats = [];
$servicoStats = [];
$agendaChart  = []; // por dia
$agendaChartPrev = [];

if ($hasAppt) {
    $priceCol = has_col($apptCols, 'total_price') ? 'total_price' : (has_col($apptCols, 'price') ? 'price' : null);
    $priceExpr = $priceCol ? 'COALESCE(' . sql_col($priceCol) . ',0)' : '0';
    $priceExprA = $priceCol ? 'COALESCE(' . sql_col($priceCol, 'a') . ',0)' : '0';
    $statusFilter = has_col($apptCols, 'status') ? " AND " . sql_col('status') . " IN ('concluido','confirmado','agendado')" : '';
    $statusFilterA = has_col($apptCols, 'status') ? " AND " . sql_col('status', 'a') . " IN ('concluido','confirmado','agendado')" : '';

    // Total agendamentos
    $totalAppt    = (int)qn($pdo,"SELECT COUNT(*) FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?",[$companyId,$dFrom,$dTo]);
    $totalApptAnt = (int)qn($pdo,"SELECT COUNT(*) FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?",[$companyId,$prevFrom,$prevTo]);
    $tAppt        = trend($totalAppt, $totalApptAnt);

    // Por status
    $statusAppt = $pdo->prepare("SELECT status, COUNT(*) as n FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ? GROUP BY status");
    $statusAppt->execute([$companyId,$dFrom,$dTo]);
    $statusMap = [];
    foreach ($statusAppt->fetchAll(PDO::FETCH_ASSOC) as $r) $statusMap[$r['status']] = (int)$r['n'];

    // Faturamento barbearia
    $fatBarb    = qn($pdo,"SELECT SUM({$priceExpr}) FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?{$statusFilter}",[$companyId,$dFrom,$dTo]);
    $fatBarbAnt = qn($pdo,"SELECT SUM({$priceExpr}) FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?{$statusFilter}",[$companyId,$prevFrom,$prevTo]);
    $tFatBarb   = trend($fatBarb, $fatBarbAnt);

    // Ticket médio
    $ticketMedio = $totalAppt > 0 ? round($fatBarb / $totalAppt, 2) : 0;

    $agendaStats = [
        'total'        => $totalAppt,
        'total_ant'    => $totalApptAnt,
        'trend'        => $tAppt,
        'status'       => $statusMap,
        'faturamento'  => $fatBarb,
        'fat_ant'      => $fatBarbAnt,
        'fat_trend'    => $tFatBarb,
        'ticket_medio' => $ticketMedio,
    ];

    // Por barbeiro
    $profCol = has_col($apptCols,'professional_name') ? 'professional_name'
             : (has_col($apptCols,'barber_name') ? 'barber_name' : null);

    if ($profCol) {
        $profSqlCol = sql_col($profCol);
        $byBarb = $pdo->prepare("SELECT {$profSqlCol} as barbeiro, COUNT(*) as atend, SUM({$priceExpr}) as fat, AVG({$priceExpr}) as ticket
            FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?{$statusFilter} AND {$profSqlCol} IS NOT NULL AND {$profSqlCol} != ''
            GROUP BY {$profSqlCol} ORDER BY fat DESC");
        $byBarb->execute([$companyId,$dFrom,$dTo]);
        $barbeirStats = $byBarb->fetchAll(PDO::FETCH_ASSOC);
    } elseif (has_col($apptCols, 'barber_id')) {
        if ($hasBarbers && has_col($barberCols, 'name')) {
            $barberCompanyJoin = has_col($barberCols, 'company_id') ? ' AND b.company_id=a.company_id' : '';
            $byBarb = $pdo->prepare("SELECT COALESCE(b.name, CONCAT('Barbeiro #', COALESCE(a.barber_id,0))) as barbeiro,
                COUNT(*) as atend, SUM({$priceExprA}) as fat, AVG({$priceExprA}) as ticket
                FROM appointments a LEFT JOIN barbers b ON b.id=a.barber_id{$barberCompanyJoin}
                WHERE a.company_id=? AND DATE(a.date) BETWEEN ? AND ?{$statusFilterA}
                GROUP BY a.barber_id, b.name ORDER BY fat DESC");
        } else {
            $byBarb = $pdo->prepare("SELECT CONCAT('Barbeiro #', COALESCE(barber_id,0)) as barbeiro,
                COUNT(*) as atend, SUM({$priceExpr}) as fat, AVG({$priceExpr}) as ticket
                FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?{$statusFilter}
                GROUP BY barber_id ORDER BY fat DESC");
        }
        $byBarb->execute([$companyId,$dFrom,$dTo]);
        $barbeirStats = $byBarb->fetchAll(PDO::FETCH_ASSOC);
    } elseif (has_col($apptCols, 'professional_id')) {
        $byBarb = $pdo->prepare("SELECT COALESCE(u.nome, CONCAT('Barbeiro #', COALESCE(a.professional_id,0))) as barbeiro,
            COUNT(*) as atend, SUM({$priceExprA}) as fat, AVG({$priceExprA}) as ticket
            FROM appointments a LEFT JOIN users u ON u.id=a.professional_id
            WHERE a.company_id=? AND DATE(a.date) BETWEEN ? AND ?{$statusFilterA}
            GROUP BY a.professional_id ORDER BY fat DESC");
        $byBarb->execute([$companyId,$dFrom,$dTo]);
        $barbeirStats = $byBarb->fetchAll(PDO::FETCH_ASSOC);
    }

    // Por serviço
    $servCol = has_col($apptCols,'service_name') ? 'service_name'
             : (has_col($apptCols,'services_json') ? null : null);
    if ($servCol) {
        $servSqlCol = sql_col($servCol);
        $byServ = $pdo->prepare("SELECT {$servSqlCol} as servico, COUNT(*) as qtd, SUM({$priceExpr}) as fat
            FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ? AND {$servSqlCol} IS NOT NULL AND {$servSqlCol} != ''
            GROUP BY {$servSqlCol} ORDER BY qtd DESC LIMIT 10");
        $byServ->execute([$companyId,$dFrom,$dTo]);
        $servicoStats = $byServ->fetchAll(PDO::FETCH_ASSOC);
    } elseif (has_col($apptCols, 'services_json')) {
        $catalog = function_exists('agenda_default_services_catalog') ? agenda_default_services_catalog() : [];
        try {
            $svcCols = $pdo->query('SHOW COLUMNS FROM services')->fetchAll(PDO::FETCH_COLUMN);
            if (has_col($svcCols, 'service_key') && has_col($svcCols, 'label')) {
                $svcRows = $pdo->prepare('SELECT service_key, label FROM services WHERE company_id=?');
                $svcRows->execute([$companyId]);
                foreach ($svcRows->fetchAll(PDO::FETCH_ASSOC) as $svcRow) {
                    $svcKey = (string)($svcRow['service_key'] ?? '');
                    if ($svcKey !== '') $catalog[$svcKey]['label'] = (string)($svcRow['label'] ?? $svcKey);
                }
            }
        } catch (Throwable $e) {}
        $servRows = $pdo->prepare("SELECT services_json, {$priceExpr} as valor
            FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ? AND services_json IS NOT NULL AND services_json != ''");
        $servRows->execute([$companyId,$dFrom,$dTo]);
        $servAgg = [];
        foreach ($servRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $services = json_decode((string)($row['services_json'] ?? ''), true);
            if (!is_array($services) || !$services) continue;

            $valor = (float)($row['valor'] ?? 0);
            $share = $valor > 0 ? $valor / max(1, count($services)) : 0;
            foreach ($services as $svc) {
                $key = is_array($svc) ? (string)($svc['service_key'] ?? $svc['key'] ?? $svc['label'] ?? '') : (string)$svc;
                $key = trim($key);
                if ($key === '') continue;

                $labelSvc = $catalog[$key]['label'] ?? $key;
                if (!isset($servAgg[$key])) $servAgg[$key] = ['servico' => $labelSvc, 'qtd' => 0, 'fat' => 0.0];
                $servAgg[$key]['qtd']++;
                $servAgg[$key]['fat'] += $share;
            }
        }
        usort($servAgg, fn($a, $b) => ($b['qtd'] <=> $a['qtd']) ?: ($b['fat'] <=> $a['fat']));
        $servicoStats = array_slice($servAgg, 0, 10);
    }

    // Gráfico por dia (agendamentos)
    $chartDays = $pdo->prepare("SELECT DATE(date) as dia, COUNT(*) as n, SUM({$priceExpr}) as fat
        FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?
        GROUP BY DATE(date) ORDER BY dia");
    $chartDays->execute([$companyId,$dFrom,$dTo]);
    $agendaChart = $chartDays->fetchAll(PDO::FETCH_ASSOC);

    // Período anterior para gráfico
    $chartPrev = $pdo->prepare("SELECT DATE(date) as dia, COUNT(*) as n, SUM({$priceExpr}) as fat
        FROM appointments WHERE company_id=? AND DATE(date) BETWEEN ? AND ?
        GROUP BY DATE(date) ORDER BY dia");
    $chartPrev->execute([$companyId,$prevFrom,$prevTo]);
    $agendaChartPrev = $chartPrev->fetchAll(PDO::FETCH_ASSOC);
}

// Gráfico pedidos por dia
$chartPedidos = $pdo->prepare("SELECT DATE(created_at) as dia, COUNT(*) as n, SUM(total) as fat
    FROM orders WHERE company_id=? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at) ORDER BY dia");
$chartPedidos->execute([$companyId,$dFrom,$dTo]);
$chartPedidos = $chartPedidos->fetchAll(PDO::FETCH_ASSOC);

// Tags
$tagRows = $pdo->prepare("SELECT tags FROM clients WHERE company_id=? AND tags IS NOT NULL AND tags != ''");
$tagRows->execute([$companyId]);
$tagCounts = [];
foreach ($tagRows->fetchAll(PDO::FETCH_COLUMN) as $t) {
    foreach (array_map('trim', explode(',', $t)) as $tag) {
        if ($tag) $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
    }
}
arsort($tagCounts);

include __DIR__ . '/views/partials/header.php';

function fmt(float $v): string { return 'R$&nbsp;' . number_format($v, 2, ',', '.'); }
function fmtN(float $v): string { return number_format($v, 0, ',', '.'); }
?>
<!------- ESTILOS ------->
<style>
/* ── Reset & base ── */
.kpi-page { font-family:'Inter',system-ui,sans-serif; color:#0f172a; }
.kpi-page *, .kpi-page *::before, .kpi-page *::after { box-sizing:border-box; }

/* ── Filtro ── */
.kpi-filter { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin-bottom:1.5rem; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.75rem 1rem; }
.kpi-filter-btn { padding:.4rem .9rem; border-radius:8px; font-size:.78rem; font-weight:600; cursor:pointer; border:1.5px solid #e2e8f0; background:#f8fafc; color:#475569; text-decoration:none; transition:all .15s; white-space:nowrap; }
.kpi-filter-btn:hover { border-color:#6366f1; color:#6366f1; }
.kpi-filter-btn.active { background:#6366f1; color:#fff; border-color:#6366f1; }
.kpi-date-inp { padding:.4rem .75rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.78rem; color:#0f172a; outline:none; background:#f8fafc; }
.kpi-date-inp:focus { border-color:#6366f1; }
.kpi-label { font-size:.8rem; font-weight:700; color:#0f172a; margin-left:auto; }
.kpi-label span { color:#6366f1; }

/* ── Seção ── */
.kpi-section { margin-bottom:2rem; }
.kpi-section-hd { display:flex; align-items:center; gap:.6rem; margin-bottom:.85rem; }
.kpi-section-hd h2 { font-size:1rem; font-weight:800; color:#0f172a; }
.kpi-section-hd span { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; background:#f1f5f9; padding:.2rem .5rem; border-radius:4px; }

/* ── KPI cards ── */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:.85rem; margin-bottom:1.25rem; }
.kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1rem 1.15rem; position:relative; overflow:hidden; }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--ac,#6366f1); }
.kpi-card-lbl { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:.35rem; }
.kpi-card-val { font-size:1.7rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-card-val.sm { font-size:1.25rem; }
.kpi-card-sub { font-size:.68rem; color:#94a3b8; margin-top:.25rem; }
.kpi-card-trend { display:flex; align-items:center; gap:.25rem; font-size:.7rem; font-weight:700; margin-top:.3rem; }

/* ── Cards duplos (2 colunas fixas para gráficos) ── */
.kpi-cols { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }
.kpi-cols.cols3 { grid-template-columns:1fr 1fr 1fr; }
@media(max-width:900px){ .kpi-cols { grid-template-columns:1fr; } .kpi-cols.cols3 { grid-template-columns:1fr; } }

/* ── Box genérico ── */
.kpi-box { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.kpi-box-hd { padding:.75rem 1.1rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.kpi-box-hd h3 { font-size:.85rem; font-weight:700; color:#0f172a; }
.kpi-box-hd span { font-size:.7rem; color:#94a3b8; }
.kpi-box-bd { padding:1rem 1.1rem; }

/* ── Tabela simples ── */
.kpi-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
.kpi-tbl th { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; padding:.5rem .75rem; text-align:left; border-bottom:1px solid #f1f5f9; background:#f8fafc; white-space:nowrap; }
.kpi-tbl td { padding:.6rem .75rem; border-bottom:1px solid #f8fafc; color:#0f172a; vertical-align:middle; }
.kpi-tbl tr:last-child td { border-bottom:none; }
.kpi-tbl tr:hover td { background:#fafafe; }

/* ── Barra de progresso ── */
.prog-bar { height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden; margin-top:.3rem; }
.prog-bar-fill { height:100%; border-radius:3px; transition:width .6s ease; }

/* ── Badge status ── */
.st-badge { display:inline-flex; align-items:center; gap:.25rem; font-size:.65rem; font-weight:700; padding:.2rem .5rem; border-radius:20px; white-space:nowrap; }
.st-concluido  { background:#dcfce7; color:#15803d; }
.st-agendado   { background:#dbeafe; color:#1d4ed8; }
.st-cancelado  { background:#fee2e2; color:#dc2626; }
.st-confirmado { background:#ede9fe; color:#6d28d9; }
.st-faltou     { background:#fef9c3; color:#a16207; }
.st-outro      { background:#f1f5f9; color:#64748b; }

/* ── Separador ── */
.kpi-div { border:none; border-top:2px solid #f1f5f9; margin:1.5rem 0; }

/* ── Chart container ── */
.chart-wrap { position:relative; height:220px; }
.chart-wrap.tall { height:280px; }

/* ── Tag chips ── */
.tag-chip { display:inline-flex; align-items:center; gap:.25rem; padding:.25rem .6rem; border-radius:20px; font-size:.72rem; font-weight:600; background:#ede9fe; color:#6d28d9; }
</style>

<div class="kpi-page">

<!-- ═══ HEADER ═══ -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:800;color:#0f172a;">📊 Central de Métricas</h1>
        <p style="font-size:.78rem;color:#64748b;margin-top:.15rem;">Período: <strong><?= $label ?></strong><?php if ($period === 'custom'): ?> · <span style="color:#94a3b8;">comparando com <?= date('d/m/Y',strtotime($prevFrom)) ?> → <?= date('d/m/Y',strtotime($prevTo)) ?></span><?php endif; ?></p>
    </div>
    <a href="dashboard.php" style="font-size:.78rem;color:#6366f1;font-weight:600;text-decoration:none;">← Dashboard</a>
</div>

<!-- ═══ FILTRO ═══ -->
<div class="kpi-filter">
    <?php
    $periods = ['hoje'=>'Hoje','7d'=>'7 dias','30d'=>'30 dias','mes'=>'Mês atual','ano'=>'Ano'];
    foreach ($periods as $k => $v):
    ?>
    <a href="?period=<?= $k ?>" class="kpi-filter-btn <?= $period===$k?'active':'' ?>"><?= $v ?></a>
    <?php endforeach; ?>
    <form method="GET" style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="date_from" class="kpi-date-inp" value="<?= $period==='custom'?$dFrom:'' ?>" max="<?= date('Y-m-d') ?>">
        <span style="font-size:.75rem;color:#94a3b8;">→</span>
        <input type="date" name="date_to"   class="kpi-date-inp" value="<?= $period==='custom'?$dTo:'' ?>"   max="<?= date('Y-m-d') ?>">
        <button type="submit" class="kpi-filter-btn <?= $period==='custom'?'active':'' ?>">Aplicar</button>
    </form>
    <span class="kpi-label">vs <span><?= date('d/m/Y',strtotime($prevFrom)) ?> → <?= date('d/m/Y',strtotime($prevTo)) ?></span></span>
</div>

<!-- ════════════════════════════════════════════════
     BLOCO 1 — LOJA
════════════════════════════════════════════════ -->
<div class="kpi-section">
    <div class="kpi-section-hd">
        <h2>🛍️ Loja</h2>
        <span>Clientes · Produtos · Vendas</span>
    </div>

    <div class="kpi-grid">
        <!-- Clientes total -->
        <div class="kpi-card" style="--ac:#6366f1;">
            <div class="kpi-card-lbl">Clientes (total)</div>
            <div class="kpi-card-val"><?= fmtN($totalClientes) ?></div>
            <div class="kpi-card-sub">cadastrados</div>
        </div>
        <!-- Clientes novos no período -->
        <div class="kpi-card" style="--ac:#8b5cf6;">
            <div class="kpi-card-lbl">Novos no período</div>
            <div class="kpi-card-val"><?= $clientesNovos ?></div>
            <div class="kpi-card-trend" style="color:<?= $tClientes['color'] ?>"><?= $tClientes['label'] ?> <span style="color:#94a3b8;font-weight:400;font-size:.65rem;">vs anterior</span></div>
        </div>
        <!-- Produtos -->
        <div class="kpi-card" style="--ac:#0ea5e9;">
            <div class="kpi-card-lbl">Produtos ativos</div>
            <div class="kpi-card-val"><?= $prodAtivos ?></div>
            <div class="kpi-card-sub"><?= $prodRemovidos ?> inativos · <?= $prodCadast ?> cadastrados no período</div>
        </div>
        <!-- Pedidos -->
        <div class="kpi-card" style="--ac:#f59e0b;">
            <div class="kpi-card-lbl">Pedidos no período</div>
            <div class="kpi-card-val"><?= $pedidos ?></div>
            <div class="kpi-card-trend" style="color:<?= $tPedidos['color'] ?>"><?= $tPedidos['label'] ?> <span style="color:#94a3b8;font-weight:400;font-size:.65rem;">vs anterior</span></div>
        </div>
        <!-- Faturamento -->
        <div class="kpi-card" style="--ac:#22c55e;">
            <div class="kpi-card-lbl">Faturamento</div>
            <div class="kpi-card-val sm"><?php echo fmt($faturamento) ?></div>
            <div class="kpi-card-trend" style="color:<?= $tFat['color'] ?>"><?= $tFat['label'] ?> <span style="color:#94a3b8;font-weight:400;font-size:.65rem;">vs anterior</span></div>
        </div>
        <!-- PDV -->
        <div class="kpi-card" style="--ac:#ec4899;">
            <div class="kpi-card-lbl">PDV no período</div>
            <div class="kpi-card-val"><?= $pdv ?></div>
            <div class="kpi-card-sub"><?php echo fmt($pdvFat) ?></div>
        </div>
        <!-- Atendimentos IA -->
        <div class="kpi-card" style="--ac:#14b8a6;">
            <div class="kpi-card-lbl">Atendimentos IA</div>
            <div class="kpi-card-val"><?= $atend ?></div>
            <div class="kpi-card-trend" style="color:<?= $tAtend['color'] ?>"><?= $tAtend['label'] ?> <span style="color:#94a3b8;font-weight:400;font-size:.65rem;">vs anterior</span></div>
        </div>
    </div>

    <div class="kpi-cols">
        <!-- Gráfico pedidos por dia -->
        <div class="kpi-box">
            <div class="kpi-box-hd"><h3>📈 Pedidos por dia</h3><span><?= $label ?></span></div>
            <div class="kpi-box-bd">
                <div class="chart-wrap"><canvas id="chart-pedidos"></canvas></div>
            </div>
        </div>
        <!-- Top produtos -->
        <div class="kpi-box">
            <div class="kpi-box-hd"><h3>🏆 Top produtos / serviços</h3><span>por faturamento</span></div>
            <div class="kpi-box-bd" style="padding:.5rem;">
                <?php if ($topProd): ?>
                <table class="kpi-tbl">
                    <thead><tr><th>Produto</th><th>Qtd</th><th>Valor</th></tr></thead>
                    <tbody>
                    <?php $maxVal = max(array_column($topProd,'valor') ?: [1]);
                    foreach ($topProd as $p): ?>
                    <tr>
                        <td><?= sanitize($p['nome']) ?></td>
                        <td style="font-family:monospace;"><?= (int)$p['qtd'] ?></td>
                        <td>
                            <div style="font-weight:700;color:#16a34a;"><?php echo fmt($p['valor']) ?></div>
                            <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= round($p['valor']/$maxVal*100) ?>%;background:#6366f1;"></div></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?><div style="text-align:center;color:#94a3b8;padding:2rem;font-size:.82rem;">Sem vendas no período</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="kpi-cols cols3">
        <!-- Por origem -->
        <div class="kpi-box">
            <div class="kpi-box-hd"><h3>🏪 Por origem</h3></div>
            <div class="kpi-box-bd" style="padding:.5rem;">
                <?php if ($origens): ?>
                <table class="kpi-tbl">
                    <thead><tr><th>Origem</th><th>Pedidos</th><th>Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($origens as $o): ?>
                    <tr><td><?= sanitize($o['origem']) ?></td><td><?= (int)$o['total'] ?></td><td style="font-weight:700;color:#16a34a;"><?php echo fmt($o['soma']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?><div style="text-align:center;color:#94a3b8;padding:1.5rem;font-size:.82rem;">Sem dados</div><?php endif; ?>
            </div>
        </div>
        <!-- Tags -->
        <div class="kpi-box" style="grid-column:span 2;">
            <div class="kpi-box-hd"><h3>🏷️ Tags dos clientes</h3><span>top 15</span></div>
            <div class="kpi-box-bd" style="display:flex;flex-wrap:wrap;gap:.4rem;">
                <?php foreach (array_slice($tagCounts,0,15,true) as $tag => $cnt): ?>
                <span class="tag-chip"><?= sanitize($tag) ?> <strong><?= $cnt ?></strong></span>
                <?php endforeach; ?>
                <?php if (empty($tagCounts)): ?><span style="color:#94a3b8;font-size:.82rem;">Sem tags</span><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<hr class="kpi-div">

<!-- ════════════════════════════════════════════════
     BLOCO 2 — BARBEARIA
════════════════════════════════════════════════ -->
<?php if ($hasAppt): ?>
<div class="kpi-section">
    <div class="kpi-section-hd">
        <h2>✂️ Barbearia</h2>
        <span>Agendamentos · Serviços · Financeiro</span>
    </div>

    <!-- KPIs gerais barbearia -->
    <div class="kpi-grid">
        <div class="kpi-card" style="--ac:#6366f1;">
            <div class="kpi-card-lbl">Agendamentos</div>
            <div class="kpi-card-val"><?= $agendaStats['total'] ?></div>
            <div class="kpi-card-trend" style="color:<?= $agendaStats['trend']['color'] ?>"><?= $agendaStats['trend']['label'] ?> <span style="color:#94a3b8;font-weight:400;font-size:.65rem;">vs anterior</span></div>
        </div>
        <div class="kpi-card" style="--ac:#22c55e;">
            <div class="kpi-card-lbl">Faturamento</div>
            <div class="kpi-card-val sm"><?php echo fmt($agendaStats['faturamento']) ?></div>
            <div class="kpi-card-trend" style="color:<?= $agendaStats['fat_trend']['color'] ?>"><?= $agendaStats['fat_trend']['label'] ?> <span style="color:#94a3b8;font-weight:400;font-size:.65rem;">vs anterior</span></div>
        </div>
        <div class="kpi-card" style="--ac:#f59e0b;">
            <div class="kpi-card-lbl">Ticket médio</div>
            <div class="kpi-card-val sm"><?php echo fmt($agendaStats['ticket_medio']) ?></div>
            <div class="kpi-card-sub">por atendimento</div>
        </div>
        <?php foreach (['concluido'=>['Concluídos','#22c55e'],'agendado'=>['Agendados','#3b82f6'],'cancelado'=>['Cancelados','#ef4444'],'faltou'=>['Faltaram','#f59e0b']] as $st=>[$lbl,$cor]): ?>
        <div class="kpi-card" style="--ac:<?= $cor ?>;">
            <div class="kpi-card-lbl"><?= $lbl ?></div>
            <div class="kpi-card-val"><?= $agendaStats['status'][$st] ?? 0 ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Gráfico barbearia + status pizza -->
    <div class="kpi-cols">
        <div class="kpi-box">
            <div class="kpi-box-hd"><h3>📈 Atendimentos por dia</h3><span>período atual vs anterior</span></div>
            <div class="kpi-box-bd"><div class="chart-wrap tall"><canvas id="chart-barb-dias"></canvas></div></div>
        </div>
        <div class="kpi-box">
            <div class="kpi-box-hd"><h3>💰 Faturamento por dia</h3></div>
            <div class="kpi-box-bd"><div class="chart-wrap tall"><canvas id="chart-barb-fat"></canvas></div></div>
        </div>
    </div>

    <!-- Por barbeiro -->
    <?php if (!empty($barbeirStats)): ?>
    <div class="kpi-box" style="margin-bottom:1rem;">
        <div class="kpi-box-hd"><h3>💈 Desempenho por barbeiro</h3><span><?= $label ?></span></div>
        <div class="kpi-box-bd" style="padding:.5rem;">
            <?php $totalFatBarbeiros = array_sum(array_map('floatval', array_column($barbeirStats, 'fat'))); ?>
            <table class="kpi-tbl">
                <thead><tr><th>Barbeiro</th><th>Atend.</th><th>Ticket médio</th><th>Faturamento</th><th style="width:120px;">Participação</th></tr></thead>
                <tbody>
                <?php foreach ($barbeirStats as $b):
                    $fatBarbeiro = (float)($b['fat'] ?? 0);
                    $pctTotal = $totalFatBarbeiros > 0 ? round($fatBarbeiro / $totalFatBarbeiros * 100, 1) : 0;
                    $pctBarb = min(100, max(0, $pctTotal));
                ?>
                <tr>
                    <td style="font-weight:700;"><?= sanitize($b['barbeiro']) ?></td>
                    <td style="font-family:monospace;font-weight:700;"><?= (int)$b['atend'] ?></td>
                    <td><?php echo fmt($b['ticket']) ?></td>
                    <td style="font-weight:700;color:#16a34a;"><?php echo fmt($b['fat']) ?></td>
                    <td>
                        <div style="font-size:.7rem;color:#64748b;margin-bottom:.2rem;"><?= $pctTotal ?>% do total</div>
                        <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= $pctBarb ?>%;background:linear-gradient(90deg,#6366f1,#8b5cf6);"></div></div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Gráfico barbeiros (barras comparativas) -->
    <div class="kpi-cols">
        <div class="kpi-box">
            <div class="kpi-box-hd"><h3>💈 Atendimentos por barbeiro</h3></div>
            <div class="kpi-box-bd"><div class="chart-wrap"><canvas id="chart-barb-comp"></canvas></div></div>
        </div>
        <div class="kpi-box">
            <div class="kpi-box-hd"><h3>💰 Faturamento por barbeiro</h3></div>
            <div class="kpi-box-bd"><div class="chart-wrap"><canvas id="chart-barb-fat-comp"></canvas></div></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Por serviço -->
    <?php if (!empty($servicoStats)): ?>
    <div class="kpi-box" style="margin-top:1rem;">
        <div class="kpi-box-hd"><h3>✂️ Serviços mais realizados</h3><span><?= $label ?></span></div>
        <div class="kpi-box-bd" style="padding:.5rem;">
            <?php $maxServ = max(array_column($servicoStats,'qtd') ?: [1]); ?>
            <table class="kpi-tbl">
                <thead><tr><th>Serviço</th><th>Qtd</th><th>Faturamento</th><th style="width:140px;">Volume</th></tr></thead>
                <tbody>
                <?php foreach ($servicoStats as $s): ?>
                <tr>
                    <td style="font-weight:600;"><?= sanitize($s['servico']) ?></td>
                    <td style="font-family:monospace;font-weight:700;"><?= (int)$s['qtd'] ?></td>
                    <td style="font-weight:700;color:#16a34a;"><?php echo fmt($s['fat']) ?></td>
                    <td>
                        <div class="prog-bar"><div class="prog-bar-fill" style="width:<?= round($s['qtd']/$maxServ*100) ?>%;background:#14b8a6;"></div></div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div style="background:#fef9c3;border:1px solid #fde68a;border-radius:12px;padding:1.25rem;color:#92400e;font-size:.85rem;margin-bottom:1.5rem;">
    ⚠️ Tabela de agendamentos não encontrada. Os dados da barbearia aparecerão aqui assim que existirem registros.
</div>
<?php endif; ?>

</div><!-- /kpi-page -->

<!-- ═══ CHART.JS ═══ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
Chart.defaults.color = '#94a3b8';

const PALETTE = {
    indigo : { bg:'rgba(99,102,241,.15)',  border:'#6366f1' },
    violet : { bg:'rgba(139,92,246,.15)',  border:'#8b5cf6' },
    green  : { bg:'rgba(34,197,94,.15)',   border:'#22c55e' },
    amber  : { bg:'rgba(245,158,11,.15)',  border:'#f59e0b' },
    sky    : { bg:'rgba(14,165,233,.15)',  border:'#0ea5e9' },
    pink   : { bg:'rgba(236,72,153,.15)',  border:'#ec4899' },
    teal   : { bg:'rgba(20,184,166,.15)',  border:'#14b8a6' },
    rose   : { bg:'rgba(239,68,68,.15)',   border:'#ef4444' },
};

const gridOpts = {
    display: true,
    color: 'rgba(226,232,240,.6)',
    drawBorder: false,
};
const tickOpts = { color:'#94a3b8', font:{ size:11 } };

/* ── Helpers ── */
function fillDays(from, to, dataArr, key) {
    const map = {};
    dataArr.forEach(r => { map[r.dia] = parseFloat(r[key]||0); });
    const result = [], labels = [];
    let d = new Date(from);
    const end = new Date(to);
    while (d <= end) {
        const k = d.toISOString().slice(0,10);
        labels.push(k.slice(5)); // MM-DD
        result.push(map[k] || 0);
        d.setDate(d.getDate()+1);
    }
    return { labels, data: result };
}

/* ── 1. Pedidos por dia ── */
<?php $jsonPedidos = json_encode($chartPedidos); ?>
(function(){
    const raw = <?= $jsonPedidos ?>;
    const { labels, data } = fillDays('<?= $dFrom ?>','<?= $dTo ?>', raw, 'n');
    const fatData = fillDays('<?= $dFrom ?>','<?= $dTo ?>', raw, 'fat').data;
    const ctx = document.getElementById('chart-pedidos');
    if (!ctx) return;
    new Chart(ctx, {
        data: {
            labels,
            datasets: [
                { type:'bar',  label:'Pedidos', data, backgroundColor: PALETTE.indigo.bg, borderColor: PALETTE.indigo.border, borderWidth:1.5, borderRadius:4, yAxisID:'y' },
                { type:'line', label:'Faturamento R$', data: fatData, borderColor: PALETTE.green.border, backgroundColor:'transparent', tension:.4, pointRadius:3, borderWidth:2, yAxisID:'y2' },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{ mode:'index', intersect:false },
            plugins:{ legend:{ labels:{ boxWidth:12, font:{size:11} } } },
            scales: {
                x:{ grid:gridOpts, ticks:tickOpts },
                y:{ grid:gridOpts, ticks:{...tickOpts, stepSize:1}, title:{ display:true, text:'Pedidos', color:'#94a3b8', font:{size:11} } },
                y2:{ position:'right', grid:{display:false}, ticks:{ ...tickOpts, callback: v => 'R$'+v.toLocaleString('pt-BR') }, title:{ display:true, text:'R$', color:'#94a3b8', font:{size:11} } }
            }
        }
    });
})();

<?php if ($hasAppt): ?>
/* ── 2. Barbearia: atendimentos por dia (atual vs anterior) ── */
<?php
$jsonBarb     = json_encode($agendaChart);
$jsonBarbPrev = json_encode($agendaChartPrev);
?>
(function(){
    const cur  = <?= $jsonBarb ?>;
    const prev = <?= $jsonBarbPrev ?>;
    const { labels, data: dataAtend } = fillDays('<?= $dFrom ?>','<?= $dTo ?>', cur, 'n');
    const dataPrev = fillDays('<?= $prevFrom ?>','<?= $prevTo ?>', prev, 'n').data;
    const ctx = document.getElementById('chart-barb-dias');
    if (!ctx) return;
    new Chart(ctx, {
        data: {
            labels,
            datasets: [
                { type:'bar', label:'Atual', data:dataAtend, backgroundColor:PALETTE.indigo.bg, borderColor:PALETTE.indigo.border, borderWidth:1.5, borderRadius:4 },
                { type:'line', label:'Anterior', data:dataPrev, borderColor:PALETTE.amber.border, backgroundColor:'transparent', tension:.4, pointRadius:3, borderDash:[4,3], borderWidth:2 },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{ mode:'index', intersect:false },
            plugins:{ legend:{ labels:{ boxWidth:12, font:{size:11} } } },
            scales: {
                x:{ grid:gridOpts, ticks:tickOpts },
                y:{ grid:gridOpts, ticks:{...tickOpts,stepSize:1} }
            }
        }
    });
})();

/* ── 3. Barbearia: faturamento por dia ── */
(function(){
    const cur = <?= $jsonBarb ?>;
    const prev = <?= $jsonBarbPrev ?>;
    const { labels, data:fatAtual } = fillDays('<?= $dFrom ?>','<?= $dTo ?>', cur, 'fat');
    const fatPrev = fillDays('<?= $prevFrom ?>','<?= $prevTo ?>', prev, 'fat').data;
    const ctx = document.getElementById('chart-barb-fat');
    if (!ctx) return;
    new Chart(ctx, {
        type:'line',
        data: {
            labels,
            datasets: [
                { label:'Atual R$', data:fatAtual, borderColor:PALETTE.green.border, backgroundColor:PALETTE.green.bg, fill:true, tension:.4, pointRadius:3, borderWidth:2 },
                { label:'Anterior R$', data:fatPrev, borderColor:PALETTE.amber.border, backgroundColor:'transparent', tension:.4, pointRadius:3, borderDash:[4,3], borderWidth:2 },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{ mode:'index', intersect:false },
            plugins:{ legend:{ labels:{ boxWidth:12, font:{size:11} } } },
            scales: {
                x:{ grid:gridOpts, ticks:tickOpts },
                y:{ grid:gridOpts, ticks:{ ...tickOpts, callback: v => 'R$'+v.toLocaleString('pt-BR') } }
            }
        }
    });
})();

<?php if (!empty($barbeirStats)): ?>
/* ── 4. Barbeiros: comparativo ── */
(function(){
    const barbs  = <?= json_encode(array_column($barbeirStats,'barbeiro')) ?>;
    const atends = <?= json_encode(array_map(fn($b)=>(int)$b['atend'],$barbeirStats)) ?>;
    const fats   = <?= json_encode(array_map(fn($b)=>(float)$b['fat'],$barbeirStats)) ?>;

    const colors = ['#6366f1','#22c55e','#f59e0b','#ec4899','#14b8a6','#8b5cf6'];
    const bgs    = ['rgba(99,102,241,.7)','rgba(34,197,94,.7)','rgba(245,158,11,.7)','rgba(236,72,153,.7)','rgba(20,184,166,.7)','rgba(139,92,246,.7)'];

    const c1 = document.getElementById('chart-barb-comp');
    if (c1) new Chart(c1, {
        type:'bar',
        data:{ labels:barbs, datasets:[{ label:'Atendimentos', data:atends, backgroundColor:bgs, borderColor:colors, borderWidth:1.5, borderRadius:6 }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} }, scales:{ x:{ grid:gridOpts, ticks:tickOpts }, y:{ grid:gridOpts, ticks:{...tickOpts,stepSize:1} } } }
    });

    const c2 = document.getElementById('chart-barb-fat-comp');
    if (c2) new Chart(c2, {
        type:'bar',
        data:{ labels:barbs, datasets:[{ label:'Faturamento R$', data:fats, backgroundColor:bgs, borderColor:colors, borderWidth:1.5, borderRadius:6 }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false} },
            scales:{ x:{ grid:gridOpts, ticks:tickOpts }, y:{ grid:gridOpts, ticks:{ ...tickOpts, callback:v=>'R$'+v.toLocaleString('pt-BR') } } }
        }
    });
})();
<?php endif; ?>
<?php endif; ?>
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
