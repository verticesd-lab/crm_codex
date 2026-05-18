<?php
// ============================================================
// club.php — Painel do Gestor — Clube For Men
// ============================================================
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

// ── Regras ───────────────────────────────────────────────────
$rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
$rules->execute([$companyId]);
$rules = $rules->fetch(PDO::FETCH_ASSOC);
if (!$rules) {
    $pdo->prepare("INSERT IGNORE INTO club_rules (company_id,nome_clube) VALUES (?,?)")
        ->execute([$companyId,'Clube For Men']);
    $rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
    $rules->execute([$companyId]);
    $rules = $rules->fetch(PDO::FETCH_ASSOC);
}

$nomeclube = $rules['nome_clube'] ?? 'Clube For Men';

// ── KPIs gerais ──────────────────────────────────────────────
// Total de clientes no clube (com carteira)
$kClientes = (int)$pdo->prepare("SELECT COUNT(*) FROM club_wallets WHERE company_id=?")
    ->execute([$companyId]) ? $pdo->query("SELECT COUNT(*) FROM club_wallets WHERE company_id=$companyId")->fetchColumn() : 0;

// Saldo total ativo
$kSaldo = (float)$pdo->query("SELECT COALESCE(SUM(saldo),0) FROM club_wallets WHERE company_id=$companyId")->fetchColumn();

// Vouchers ativos não usados
$kVouchers = (int)$pdo->query("SELECT COUNT(*) FROM club_vouchers WHERE company_id=$companyId AND usado=0 AND expira_em>=CURDATE()")->fetchColumn();

// Transações hoje
$kHoje = (int)$pdo->query("SELECT COUNT(*) FROM club_transactions WHERE company_id=$companyId AND DATE(created_at)=CURDATE()")->fetchColumn();

// Cashback gerado no mês
$kMes = (float)$pdo->query("SELECT COALESCE(SUM(valor),0) FROM club_transactions WHERE company_id=$companyId AND tipo='credito' AND DATE_FORMAT(created_at,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();

// ── Alertas ──────────────────────────────────────────────────
// Saldo vencendo em até 7 dias
$vencendo7 = $pdo->query("
    SELECT COUNT(DISTINCT t.client_id) FROM club_transactions t
    WHERE t.company_id=$companyId AND t.tipo='credito'
      AND t.expira_em BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
")->fetchColumn();

// Clientes há 30+ dias sem corte (selos)
$semCorte30 = $pdo->query("
    SELECT COUNT(*) FROM club_stamp_history h
    INNER JOIN (
        SELECT client_id, MAX(created_at) as ultima FROM club_stamp_history
        WHERE company_id=$companyId GROUP BY client_id
    ) u ON h.client_id=u.client_id AND h.created_at=u.ultima
    WHERE h.company_id=$companyId AND u.ultima < DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetchColumn();

// Clientes com 3/4 selos (quase no prêmio)
$selosMeta = (int)($rules['selos_premio'] ?? 4);
$quaseGanhando = $pdo->query("
    SELECT COUNT(*) FROM club_stamps
    WHERE company_id=$companyId AND selos_ciclo=".($selosMeta-1)."
")->fetchColumn();

// ── Top clientes (por total ganho) ───────────────────────────
$topClientes = $pdo->query("
    SELECT w.*, c.nome, c.whatsapp,
           (SELECT selos_ciclo FROM club_stamps WHERE company_id=$companyId AND client_id=w.client_id LIMIT 1) as selos_ciclo,
           (SELECT total_premios FROM club_stamps WHERE company_id=$companyId AND client_id=w.client_id LIMIT 1) as total_premios
    FROM club_wallets w
    INNER JOIN clients c ON c.id=w.client_id
    WHERE w.company_id=$companyId
    ORDER BY w.total_ganho DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Clientes com saldo vencendo (até 7 dias) ─────────────────
$clientesVencendo = $pdo->query("
    SELECT DISTINCT c.id, c.nome, c.whatsapp, w.saldo,
        MIN(t.expira_em) as proxima_expiracao
    FROM club_transactions t
    INNER JOIN club_wallets w ON w.client_id=t.client_id AND w.company_id=t.company_id
    INNER JOIN clients c ON c.id=t.client_id
    WHERE t.company_id=$companyId AND t.tipo='credito'
      AND t.expira_em BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    GROUP BY c.id, c.nome, c.whatsapp, w.saldo
    ORDER BY proxima_expiracao ASC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Clientes quase ganhando prêmio ───────────────────────────
$clientesQuase = $pdo->query("
    SELECT s.*, c.nome, c.whatsapp
    FROM club_stamps s
    INNER JOIN clients c ON c.id=s.client_id
    WHERE s.company_id=$companyId AND s.selos_ciclo=".($selosMeta-1)."
    ORDER BY s.updated_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Últimas transações ────────────────────────────────────────
$ultimasTrans = $pdo->query("
    SELECT t.*, c.nome as cliente_nome
    FROM club_transactions t
    INNER JOIN clients c ON c.id=t.client_id
    WHERE t.company_id=$companyId
    ORDER BY t.created_at DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

function fmt_r(float $v): string { return 'R$' . number_format($v,2,',','.'); }

include __DIR__ . '/views/partials/header.php';
?>
<style>
/* ── KPI grid ── */
.kpi-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(155px,1fr)); gap:.85rem; margin-bottom:1.4rem; }
.kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1rem 1.25rem; position:relative; overflow:hidden; }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--ac,#6366f1); border-radius:14px 14px 0 0; }
.kpi-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:.4rem; }
.kpi-val { font-size:1.9rem; font-weight:800; color:#0f172a; line-height:1; }
.kpi-sub { font-size:.65rem; color:#94a3b8; margin-top:.3rem; }

/* ── Alertas ── */
.alerts-row { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.alert-chip { display:flex; align-items:center; gap:.5rem; padding:.6rem 1rem; border-radius:10px; font-size:.8rem; font-weight:600; text-decoration:none; transition:filter .15s; }
.alert-chip:hover { filter:brightness(.95); }
.alert-chip.red    { background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; }
.alert-chip.amber  { background:#fef9c3; color:#a16207; border:1px solid #fde68a; }
.alert-chip.purple { background:#ede9fe; color:#6d28d9; border:1px solid #c4b5fd; }
.alert-num { font-size:1.1rem; font-weight:800; }

/* ── Bottom grid ── */
.dash-bottom { display:grid; grid-template-columns:1fr 1fr; gap:1.1rem; }
.dp { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.dp-hd { padding:.85rem 1.25rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.dp-title { font-size:.88rem; font-weight:700; color:#0f172a; }
.dp-sub   { font-size:.68rem; color:#94a3b8; }
.dp-body  { padding:.75rem 1rem; }

/* ── Linhas de cliente ── */
.cli-row { display:flex; align-items:center; gap:.65rem; padding:.55rem .35rem; border-bottom:1px solid #f8fafc; transition:background .1s; border-radius:8px; }
.cli-row:hover { background:#f8fafc; }
.cli-row:last-child { border-bottom:none; }
.cli-av { width:32px; height:32px; border-radius:50%; background:#ede9fe; color:#6d28d9; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:800; flex-shrink:0; }
.cli-info { flex:1; min-width:0; }
.cli-name { font-size:.8rem; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cli-meta { font-size:.68rem; color:#94a3b8; margin-top:.05rem; }
.cli-val  { text-align:right; flex-shrink:0; }
.cli-val .main { font-size:.82rem; font-weight:700; color:#0f172a; }
.cli-val .sub  { font-size:.65rem; color:#94a3b8; }

/* ── Selos visual mini ── */
.selos-mini { display:flex; gap:2px; }
.sm { width:10px; height:10px; border-radius:50%; background:#f1f5f9; border:1px solid #e2e8f0; }
.sm.on { background:#f59e0b; border-color:#d97706; }

/* ── Trans ── */
.tr-row { display:flex; align-items:center; gap:.6rem; padding:.5rem .35rem; border-bottom:1px solid #f8fafc; }
.tr-row:last-child { border-bottom:none; }
.tr-ico { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; flex-shrink:0; }
.tr-ico.c { background:#dcfce7; }
.tr-ico.d { background:#fee2e2; }
.tr-ico.v { background:#fef9c3; }
.tr-info { flex:1; min-width:0; }
.tr-nome { font-size:.78rem; font-weight:600; color:#0f172a; }
.tr-desc { font-size:.67rem; color:#94a3b8; }
.tr-val.c { color:#16a34a; font-weight:700; font-size:.8rem; }
.tr-val.d { color:#dc2626; font-weight:700; font-size:.8rem; }
.tr-val.v { color:#a16207; font-weight:700; font-size:.8rem; }
.tr-hora { font-size:.65rem; color:#cbd5e1; white-space:nowrap; }

/* ── Top table ── */
.top-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.top-table th { text-align:left; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; padding:.5rem .35rem; border-bottom:1px solid #f1f5f9; }
.top-table td { padding:.55rem .35rem; border-bottom:1px solid #f8fafc; vertical-align:middle; }
.top-table tr:last-child td { border-bottom:none; }
.rank { width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:.68rem; font-weight:800; }
.rank.gold   { background:#fef9c3; color:#a16207; }
.rank.silver { background:#f1f5f9; color:#475569; }
.rank.bronze { background:#fef3c7; color:#92400e; }
.rank.other  { background:#f8fafc; color:#94a3b8; }

/* ── Ações rápidas ── */
.act-bar { display:flex; gap:.75rem; margin-bottom:1.4rem; flex-wrap:wrap; }
.act-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem 1.1rem; border-radius:9px; font-size:.8rem; font-weight:700; text-decoration:none; border:none; cursor:pointer; transition:filter .15s; }
.act-btn:hover { filter:brightness(.93); }
.act-btn.primary { background:#6366f1; color:#fff; }
.act-btn.green   { background:#22c55e; color:#fff; }
.act-btn.amber   { background:#f59e0b; color:#fff; }
.act-btn.slate   { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }

/* ── Empty ── */
.empty-state { text-align:center; padding:2rem 1rem; color:#94a3b8; font-size:.8rem; }
.empty-state span { font-size:1.8rem; display:block; margin-bottom:.5rem; }

@media(max-width:768px) {
  .dash-bottom { grid-template-columns:1fr; }
  .kpi-grid    { grid-template-columns:repeat(2,1fr); }
}
</style>

<!-- Topbar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
    <h1 style="font-size:1.25rem;font-weight:800;color:#0f172a;">⚡ <?= sanitize($nomeclube) ?> — Painel</h1>
    <div class="act-bar" style="margin-bottom:0;">
        <a href="club_cashier.php" class="act-btn primary">🧾 Abrir Caixa</a>
        <a href="club_rules.php"   class="act-btn amber">⚙️ Regras</a>
        <a href="clube.php"        class="act-btn slate" target="_blank">👁 Página do Cliente ↗</a>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-grid">
    <div class="kpi-card" style="--ac:#6366f1;">
        <p class="kpi-lbl">Clientes no Clube</p>
        <p class="kpi-val"><?= number_format($kClientes) ?></p>
        <p class="kpi-sub">com carteira ativa</p>
    </div>
    <div class="kpi-card" style="--ac:#22c55e;">
        <p class="kpi-lbl">Saldo Total Ativo</p>
        <p class="kpi-val" style="font-size:1.4rem;"><?= fmt_r($kSaldo) ?></p>
        <p class="kpi-sub">a resgatar pelos clientes</p>
    </div>
    <div class="kpi-card" style="--ac:#f59e0b;">
        <p class="kpi-lbl">Vouchers Ativos</p>
        <p class="kpi-val"><?= $kVouchers ?></p>
        <p class="kpi-sub">não utilizados</p>
    </div>
    <div class="kpi-card" style="--ac:#0ea5e9;">
        <p class="kpi-lbl">Cashback no Mês</p>
        <p class="kpi-val" style="font-size:1.4rem;"><?= fmt_r($kMes) ?></p>
        <p class="kpi-sub"><?= $kHoje ?> transações hoje</p>
    </div>
</div>

<!-- Alertas / Atenção -->
<?php if ($vencendo7 > 0 || $semCorte30 > 0 || $quaseGanhando > 0): ?>
<div class="alerts-row">
    <?php if ($vencendo7 > 0): ?>
    <div class="alert-chip red">
        <span class="alert-num"><?= $vencendo7 ?></span>
        ⏰ saldo vencendo em 7 dias
    </div>
    <?php endif; ?>
    <?php if ($semCorte30 > 0): ?>
    <div class="alert-chip amber">
        <span class="alert-num"><?= $semCorte30 ?></span>
        ✂️ sem corte há 30+ dias
    </div>
    <?php endif; ?>
    <?php if ($quaseGanhando > 0): ?>
    <div class="alert-chip purple">
        <span class="alert-num"><?= $quaseGanhando ?></span>
        🎁 falta 1 corte para o prêmio
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Grid principal -->
<div class="dash-bottom" style="margin-bottom:1.1rem;">

    <!-- Top clientes -->
    <div class="dp">
        <div class="dp-hd">
            <div>
                <p class="dp-title">🏆 Top Clientes</p>
                <p class="dp-sub">por total acumulado no clube</p>
            </div>
        </div>
        <div class="dp-body">
            <?php if (empty($topClientes)): ?>
            <div class="empty-state"><span>👥</span>Nenhum cliente no clube ainda.</div>
            <?php else: ?>
            <table class="top-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cliente</th>
                        <th>Saldo</th>
                        <th>Selos</th>
                        <th>Total ganho</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topClientes as $i => $c):
                    $rankClass = $i===0?'gold':($i===1?'silver':($i===2?'bronze':'other'));
                    $rankLabel = $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':($i+1)));
                ?>
                <tr>
                    <td><span class="rank <?= $rankClass ?>"><?= $rankLabel ?></span></td>
                    <td>
                        <div style="font-weight:600;color:#0f172a;"><?= sanitize(explode(' ',$c['nome'])[0] . ' ' . (explode(' ',$c['nome'])[1] ?? '')) ?></div>
                        <div style="font-size:.65rem;color:#94a3b8;"><?= sanitize($c['whatsapp'] ?? '') ?></div>
                    </td>
                    <td style="color:<?= $c['saldo']>0?'#16a34a':'#94a3b8' ?>;font-weight:700;"><?= fmt_r($c['saldo']) ?></td>
                    <td>
                        <div class="selos-mini">
                            <?php for($s=1;$s<=$selosMeta;$s++): ?>
                            <div class="sm <?= $s<=(int)($c['selos_ciclo']??0)?'on':'' ?>"></div>
                            <?php endfor; ?>
                        </div>
                        <div style="font-size:.65rem;color:#94a3b8;margin-top:2px;"><?= (int)($c['selos_ciclo']??0) ?>/<?= $selosMeta ?></div>
                    </td>
                    <td style="font-weight:700;color:#6366f1;"><?= fmt_r($c['total_ganho']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Últimas transações -->
    <div class="dp">
        <div class="dp-hd">
            <div>
                <p class="dp-title">🕐 Últimas Movimentações</p>
                <p class="dp-sub">cashback e resgates recentes</p>
            </div>
        </div>
        <div class="dp-body">
            <?php if (empty($ultimasTrans)): ?>
            <div class="empty-state"><span>💸</span>Nenhuma movimentação ainda.</div>
            <?php else: ?>
            <?php foreach ($ultimasTrans as $t):
                $tc = in_array($t['tipo'],['credito']) ? 'c' : (in_array($t['tipo'],['debito','expiracao']) ? 'd' : 'v');
                $icon = ['credito'=>'💰','debito'=>'🏷️','expiracao'=>'⏰','voucher_selos'=>'🎁','voucher_turbinado'=>'⚡'][$t['tipo']] ?? '•';
                $sinal = $tc==='c' ? '+' : '-';
            ?>
            <div class="tr-row">
                <div class="tr-ico <?= $tc ?>"><?= $icon ?></div>
                <div class="tr-info">
                    <div class="tr-nome"><?= sanitize($t['cliente_nome'] ?? '') ?></div>
                    <div class="tr-desc"><?= sanitize($t['descricao'] ?? $t['tipo']) ?></div>
                </div>
                <div style="text-align:right;">
                    <div class="tr-val <?= $tc ?>"><?= $sinal ?><?= fmt_r($t['valor']) ?></div>
                    <div class="tr-hora"><?= date('d/m H:i',strtotime($t['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /dash-bottom -->

<!-- Segunda linha -->
<div class="dash-bottom">

    <!-- Saldo vencendo -->
    <div class="dp">
        <div class="dp-hd">
            <div>
                <p class="dp-title">⏰ Saldo Vencendo em 7 Dias</p>
                <p class="dp-sub">clientes que precisam de contato urgente</p>
            </div>
        </div>
        <div class="dp-body">
            <?php if (empty($clientesVencendo)): ?>
            <div class="empty-state"><span>✅</span>Nenhum saldo vencendo esta semana.</div>
            <?php else: ?>
            <?php foreach ($clientesVencendo as $c): ?>
            <div class="cli-row">
                <div class="cli-av"><?= strtoupper(substr($c['nome'],0,1)) ?></div>
                <div class="cli-info">
                    <div class="cli-name"><?= sanitize($c['nome']) ?></div>
                    <div class="cli-meta">Expira <?= date('d/m',strtotime($c['proxima_expiracao'])) ?></div>
                </div>
                <div class="cli-val">
                    <div class="main" style="color:#dc2626;"><?= fmt_r($c['saldo']) ?></div>
                    <?php if (!empty($c['whatsapp'])): ?>
                    <a href="https://wa.me/<?= preg_replace('/\D+/','',$c['whatsapp']) ?>?text=<?= urlencode('Olá ' . explode(' ',$c['nome'])[0] . '! Seu saldo de R$' . number_format($c['saldo'],2,',','.') . ' no Clube For Men vence em breve. Venha aproveitar! 🔥') ?>"
                       target="_blank" style="font-size:.65rem;color:#6366f1;text-decoration:none;">WhatsApp ↗</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quase ganhando prêmio -->
    <div class="dp">
        <div class="dp-hd">
            <div>
                <p class="dp-title">🎁 Falta 1 Corte para o Prêmio</p>
                <p class="dp-sub">ótimo momento para chamar no WhatsApp</p>
            </div>
        </div>
        <div class="dp-body">
            <?php if (empty($clientesQuase)): ?>
            <div class="empty-state"><span>✂️</span>Nenhum cliente nesta fase ainda.</div>
            <?php else: ?>
            <?php foreach ($clientesQuase as $c): ?>
            <div class="cli-row">
                <div class="cli-av" style="background:#fef9c3;color:#a16207;"><?= strtoupper(substr($c['nome'],0,1)) ?></div>
                <div class="cli-info">
                    <div class="cli-name"><?= sanitize($c['nome']) ?></div>
                    <div class="cli-meta">
                        <div class="selos-mini" style="display:inline-flex;">
                            <?php for($s=1;$s<=$selosMeta;$s++): ?>
                            <div class="sm <?= $s<=$c['selos_ciclo']?'on':'' ?>"></div>
                            <?php endfor; ?>
                        </div>
                        <?= $c['selos_ciclo'] ?>/<?= $selosMeta ?>
                    </div>
                </div>
                <div class="cli-val">
                    <?php if (!empty($c['whatsapp'])): ?>
                    <a href="https://wa.me/<?= preg_replace('/\D+/','',$c['whatsapp']) ?>?text=<?= urlencode('Olá ' . explode(' ',$c['nome'])[0] . '! Você está a 1 corte de ganhar R$' . number_format($rules['voucher_valor'],2,',','.') . ' para usar na loja! Agende já 🔥') ?>"
                       target="_blank" class="act-btn amber" style="font-size:.7rem;padding:.3rem .7rem;">
                        Chamar ↗
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>