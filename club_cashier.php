<?php
// ============================================================
// club_cashier.php — Caixa do Clube For Men
// Funcionário digita WhatsApp → vê saldo + selos → age
// ============================================================
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo       = get_pdo();
$companyId = current_company_id();
$userId    = $_SESSION['user_id'] ?? 0;

// ── Regras do clube ───────────────────────────────────────────
$rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
$rules->execute([$companyId]);
$rules = $rules->fetch();
if (!$rules) {
    $pdo->prepare("INSERT IGNORE INTO club_rules (company_id,nome_clube) VALUES (?,?)")
        ->execute([$companyId,'Clube For Men']);
    $rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
    $rules->execute([$companyId]);
    $rules = $rules->fetch();
}

// ── Helpers ───────────────────────────────────────────────────
function fmt_r(float $v): string { return 'R$' . number_format($v,2,',','.'); }

function get_or_create_wallet(PDO $pdo, int $cid, int $clientId): array {
    $s = $pdo->prepare("SELECT * FROM club_wallets WHERE company_id=? AND client_id=? LIMIT 1");
    $s->execute([$cid, $clientId]);
    $w = $s->fetch(PDO::FETCH_ASSOC);
    if (!$w) {
        $pdo->prepare("INSERT INTO club_wallets (company_id,client_id) VALUES (?,?)")->execute([$cid,$clientId]);
        $id = (int)$pdo->lastInsertId();
        $w = ['id'=>$id,'company_id'=>$cid,'client_id'=>$clientId,'saldo'=>0,'total_ganho'=>0,'total_resgatado'=>0];
    }
    return $w;
}

function get_or_create_stamp(PDO $pdo, int $cid, int $clientId): array {
    $s = $pdo->prepare("SELECT * FROM club_stamps WHERE company_id=? AND client_id=? LIMIT 1");
    $s->execute([$cid, $clientId]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if (!$st) {
        $pdo->prepare("INSERT INTO club_stamps (company_id,client_id) VALUES (?,?)")->execute([$cid,$clientId]);
        $id = (int)$pdo->lastInsertId();
        $st = ['id'=>$id,'total_selos'=>0,'selos_ciclo'=>0,'total_premios'=>0];
    }
    return $st;
}

function gerar_codigo_voucher(): string {
    return strtoupper(substr(md5(uniqid('v',true)),0,8));
}

$msg  = '';
$erro = '';
$cliente = null;
$wallet  = null;
$stamp   = null;
$vouchers_ativos = [];
$historico = [];

// ── BUSCA cliente por WhatsApp ─────────────────────────────────
$whatsInput = '';
if (isset($_GET['w']) || isset($_POST['whatsapp'])) {
    $whatsInput = preg_replace('/\D+/','', trim($_GET['w'] ?? $_POST['whatsapp'] ?? ''));
    if (strlen($whatsInput) >= 8) {
        $s = $pdo->prepare("SELECT * FROM clients WHERE company_id=? AND whatsapp LIKE ? LIMIT 1");
        $s->execute([$companyId, '%' . substr($whatsInput,-8)]);
        $cliente = $s->fetch(PDO::FETCH_ASSOC);
    }
}

// ── AÇÕES POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cliente) {
    $acao     = $_POST['acao'] ?? '';
    $clientId = (int)$cliente['id'];
    $wallet   = get_or_create_wallet($pdo, $companyId, $clientId);
    $stamp    = get_or_create_stamp($pdo, $companyId, $clientId);

    // ── Registrar venda (cashback) ─────────────────────────────
    if ($acao === 'registrar_venda') {
        $valorVenda = (float)str_replace(',','.',$_POST['valor_venda'] ?? '0');
        if ($valorVenda < (float)$rules['cashback_minimo'] && (float)$rules['cashback_minimo'] > 0) {
            $erro = 'Venda mínima para gerar cashback: ' . fmt_r($rules['cashback_minimo']);
        } elseif ($valorVenda <= 0) {
            $erro = 'Informe o valor da venda.';
        } else {
            $cashback = round($valorVenda * $rules['cashback_pct'] / 100, 2);
            $expira   = date('Y-m-d', strtotime('+' . $rules['cashback_validade'] . ' days'));
            // Credita na carteira
            $pdo->prepare("UPDATE club_wallets SET saldo=saldo+?, total_ganho=total_ganho+?, updated_at=NOW() WHERE id=?")
                ->execute([$cashback, $cashback, $wallet['id']]);
            // Registra transação
            $pdo->prepare("INSERT INTO club_transactions (company_id,client_id,wallet_id,tipo,valor,descricao,referencia_tipo,expira_em) VALUES (?,?,?,'credito',?,?,?,?)")
                ->execute([$companyId,$clientId,$wallet['id'],$cashback,"Cashback de venda de " . fmt_r($valorVenda),'venda',$expira]);
            $msg = "✅ Cashback de " . fmt_r($cashback) . " creditado! Expira em {$rules['cashback_validade']} dias.";
            // Recarrega wallet
            $wallet = get_or_create_wallet($pdo, $companyId, $clientId);
        }
    }

    // ── Resgatar saldo (cashback) ──────────────────────────────
    if ($acao === 'resgatar_saldo') {
        $wallet = get_or_create_wallet($pdo, $companyId, $clientId);
        $saldo  = (float)$wallet['saldo'];
        if ($saldo < (float)$rules['resgate_minimo']) {
            $erro = 'Saldo mínimo para resgatar: ' . fmt_r($rules['resgate_minimo']) . '. Saldo atual: ' . fmt_r($saldo);
        } else {
            $valorResgate = (float)str_replace(',','.',$_POST['valor_resgate'] ?? $saldo);
            $valorResgate = min($valorResgate, $saldo);
            $pdo->prepare("UPDATE club_wallets SET saldo=saldo-?, total_resgatado=total_resgatado+?, updated_at=NOW() WHERE id=?")
                ->execute([$valorResgate, $valorResgate, $wallet['id']]);
            $pdo->prepare("INSERT INTO club_transactions (company_id,client_id,wallet_id,tipo,valor,descricao,referencia_tipo) VALUES (?,?,?,'debito',?,?,?)")
                ->execute([$companyId,$clientId,$wallet['id'],$valorResgate,'Resgate de cashback','resgate']);
            $msg = "✅ " . fmt_r($valorResgate) . " resgatados com sucesso!";
            $wallet = get_or_create_wallet($pdo, $companyId, $clientId);
        }
    }

    // ── Adicionar selo (corte) ─────────────────────────────────
    if ($acao === 'adicionar_selo') {
        $stamp  = get_or_create_stamp($pdo, $companyId, $clientId);
        $obs    = trim($_POST['obs_selo'] ?? 'Corte registrado');
        $novosCiclo = $stamp['selos_ciclo'] + 1;
        $novosTotal = $stamp['total_selos'] + 1;

        if ($novosCiclo >= (int)$rules['selos_premio']) {
            // Ganhou voucher!
            $codigo  = gerar_codigo_voucher();
            $expira  = date('Y-m-d', strtotime('+' . $rules['voucher_validade'] . ' days'));
            $pdo->prepare("INSERT INTO club_vouchers (company_id,client_id,codigo,tipo,valor,expira_em,obs) VALUES (?,?,?,'selos',?,?,?)")
                ->execute([$companyId,$clientId,$codigo,$rules['voucher_valor'],$expira,'Voucher por ' . $rules['selos_premio'] . ' selos']);
            $pdo->prepare("UPDATE club_stamps SET total_selos=?,selos_ciclo=0,total_premios=total_premios+1,updated_at=NOW() WHERE id=?")
                ->execute([$novosTotal,$stamp['id']]);
            $pdo->prepare("INSERT INTO club_stamp_history (company_id,client_id,stamp_id,obs,adicionado_por) VALUES (?,?,?,?,?)")
                ->execute([$companyId,$clientId,$stamp['id'],$obs,$userId]);
            $msg = "🎉 Prêmio desbloqueado! Voucher <strong>{$codigo}</strong> de " . fmt_r($rules['voucher_valor']) . " gerado! Expira em {$rules['voucher_validade']} dias.";
        } else {
            $pdo->prepare("UPDATE club_stamps SET total_selos=?,selos_ciclo=?,updated_at=NOW() WHERE id=?")
                ->execute([$novosTotal,$novosCiclo,$stamp['id']]);
            $pdo->prepare("INSERT INTO club_stamp_history (company_id,client_id,stamp_id,obs,adicionado_por) VALUES (?,?,?,?,?)")
                ->execute([$companyId,$clientId,$stamp['id'],$obs,$userId]);
            $faltam = (int)$rules['selos_premio'] - $novosCiclo;
            $msg = "✅ Selo adicionado! {$novosCiclo}/{$rules['selos_premio']} — faltam {$faltam} para o prêmio.";
        }
        $stamp = get_or_create_stamp($pdo, $companyId, $clientId);
    }

    // ── Voucher turbinado ──────────────────────────────────────
    if ($acao === 'voucher_turbinado') {
        $valor  = (float)str_replace(',','.',$_POST['valor_turbinado'] ?? $rules['voucher_turbinado']);
        $obs    = trim($_POST['obs_turbinado'] ?? 'Voucher Turbinado');
        $codigo = gerar_codigo_voucher();
        $expira = date('Y-m-d', strtotime('+1 day')); // válido só hoje
        $pdo->prepare("INSERT INTO club_vouchers (company_id,client_id,codigo,tipo,valor,expira_em,obs) VALUES (?,?,?,'turbinado',?,?,?)")
            ->execute([$companyId,$clientId,$codigo,$valor,$expira,$obs]);
        $msg = "⚡ Voucher turbinado <strong>{$codigo}</strong> de " . fmt_r($valor) . " gerado! Válido por 24h.";
    }

    // ── Usar voucher ───────────────────────────────────────────
    if ($acao === 'usar_voucher') {
        $voucherCod = strtoupper(trim($_POST['voucher_codigo'] ?? ''));
        $sv = $pdo->prepare("SELECT * FROM club_vouchers WHERE company_id=? AND client_id=? AND codigo=? AND usado=0 AND expira_em >= CURDATE() LIMIT 1");
        $sv->execute([$companyId,$clientId,$voucherCod]);
        $vRow = $sv->fetch(PDO::FETCH_ASSOC);
        if (!$vRow) {
            $erro = 'Voucher inválido, já usado ou expirado.';
        } else {
            $pdo->prepare("UPDATE club_vouchers SET usado=1,usado_em=NOW() WHERE id=?")->execute([$vRow['id']]);
            $msg = "✅ Voucher " . fmt_r($vRow['valor']) . " aplicado com sucesso!";
        }
    }
}

// ── Carrega dados do cliente se encontrado ─────────────────────
if ($cliente) {
    $clientId = (int)$cliente['id'];
    $wallet   = get_or_create_wallet($pdo, $companyId, $clientId);
    $stamp    = get_or_create_stamp($pdo, $companyId, $clientId);

    // Vouchers ativos
    $sv = $pdo->prepare("SELECT * FROM club_vouchers WHERE company_id=? AND client_id=? AND usado=0 AND expira_em>=CURDATE() ORDER BY created_at DESC");
    $sv->execute([$companyId,$clientId]);
    $vouchers_ativos = $sv->fetchAll(PDO::FETCH_ASSOC);

    // Últimas 10 transações
    $sh = $pdo->prepare("SELECT * FROM club_transactions WHERE company_id=? AND client_id=? ORDER BY created_at DESC LIMIT 10");
    $sh->execute([$companyId,$clientId]);
    $historico = $sh->fetchAll(PDO::FETCH_ASSOC);
}

// Expira cashbacks vencidos (roda silenciosamente)
try {
    $pdo->prepare("
        UPDATE club_wallets w
        INNER JOIN (
            SELECT wallet_id, SUM(valor) as total_exp
            FROM club_transactions
            WHERE company_id=? AND tipo='credito' AND expira_em < CURDATE()
              AND id NOT IN (SELECT referencia_id FROM club_transactions WHERE tipo='expiracao' AND referencia_tipo='transaction' AND referencia_id IS NOT NULL)
            GROUP BY wallet_id
        ) exp ON w.id = exp.wallet_id
        SET w.saldo = GREATEST(0, w.saldo - exp.total_exp)
        WHERE w.company_id=?
    ")->execute([$companyId,$companyId]);
} catch(\Exception $e) {}

include __DIR__ . '/views/partials/header.php';
?>
<style>
.club-wrap { max-width:880px; }
.club-search { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.5rem; margin-bottom:1.25rem; }
.club-search h2 { font-size:1rem; font-weight:700; color:#0f172a; margin-bottom:1rem; }
.search-row { display:flex; gap:.6rem; }
.search-inp { flex:1; padding:.65rem 1rem; border:1.5px solid #e2e8f0; border-radius:9px; font-size:.95rem; outline:none; }
.search-inp:focus { border-color:#6366f1; }
.search-btn { padding:.65rem 1.25rem; background:#6366f1; color:#fff; border:none; border-radius:9px; font-weight:700; cursor:pointer; font-size:.9rem; }
.search-btn:hover { background:#4f46e5; }

/* Cliente card */
.client-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.client-hd { background:linear-gradient(135deg,#1e293b,#334155); padding:1.25rem 1.5rem; display:flex; align-items:center; gap:1rem; }
.client-av { width:52px; height:52px; border-radius:50%; background:#6366f1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.3rem; font-weight:800; flex-shrink:0; border:2px solid rgba(255,255,255,.2); }
.client-info h3 { font-size:1.1rem; font-weight:700; color:#fff; }
.client-info p  { font-size:.8rem; color:#94a3b8; margin-top:.1rem; }

/* Métricas */
.metrics { display:grid; grid-template-columns:repeat(3,1fr); gap:1px; background:#e2e8f0; }
.metric  { background:#f8fafc; padding:1rem 1.25rem; text-align:center; }
.metric .val { font-size:1.6rem; font-weight:800; line-height:1; }
.metric .lbl { font-size:.65rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-top:.25rem; }

/* Selos visual */
.selos-row { display:flex; gap:.4rem; justify-content:center; padding:.75rem 1.25rem; background:#fff; border-top:1px solid #f1f5f9; flex-wrap:wrap; }
.selo { width:36px; height:36px; border-radius:50%; border:2px solid #e2e8f0; display:flex; align-items:center; justify-content:center; font-size:1rem; background:#f8fafc; }
.selo.on  { background:#fef9c3; border-color:#f59e0b; }
.selo.win { background:#dcfce7; border-color:#22c55e; animation:pop .4s ease; }
@keyframes pop { 0%{transform:scale(.8)} 60%{transform:scale(1.2)} 100%{transform:scale(1)} }

/* Ações */
.actions-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; padding:1.25rem; }
.action-block { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px; padding:1rem; }
.action-block h4 { font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#64748b; margin-bottom:.75rem; }
.a-inp { width:100%; padding:.5rem .75rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.9rem; margin-bottom:.5rem; outline:none; box-sizing:border-box; }
.a-inp:focus { border-color:#6366f1; }
.a-btn { width:100%; padding:.6rem; border:none; border-radius:8px; font-size:.85rem; font-weight:700; cursor:pointer; }
.a-btn.green  { background:#22c55e; color:#fff; }
.a-btn.green:hover { background:#16a34a; }
.a-btn.indigo { background:#6366f1; color:#fff; }
.a-btn.indigo:hover { background:#4f46e5; }
.a-btn.amber  { background:#f59e0b; color:#fff; }
.a-btn.amber:hover { background:#d97706; }
.a-btn.red    { background:#ef4444; color:#fff; }
.a-btn.red:hover { background:#dc2626; }

/* Vouchers */
.voucher-list { padding:0 1.25rem 1rem; }
.voucher-item { display:flex; align-items:center; justify-content:space-between; padding:.6rem .85rem; background:#fef9c3; border:1px solid #fde68a; border-radius:8px; margin-bottom:.4rem; font-size:.82rem; }
.voucher-code { font-family:monospace; font-weight:800; font-size:.95rem; color:#92400e; }
.voucher-val  { font-weight:700; color:#15803d; }
.voucher-exp  { font-size:.7rem; color:#92400e; }

/* Histórico */
.hist-list { padding:0 1.25rem 1.25rem; }
.hist-item { display:flex; align-items:center; gap:.75rem; padding:.5rem .4rem; border-bottom:1px solid #f8fafc; font-size:.8rem; }
.hist-item:last-child { border-bottom:none; }
.hist-tipo { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; flex-shrink:0; }
.hist-tipo.c { background:#dcfce7; }
.hist-tipo.d { background:#fee2e2; }
.hist-tipo.e { background:#f1f5f9; }
.hist-desc { flex:1; color:#475569; }
.hist-val.c { color:#15803d; font-weight:700; }
.hist-val.d { color:#dc2626; font-weight:700; }
.hist-date { color:#94a3b8; font-size:.7rem; white-space:nowrap; }

/* Alerts */
.alert-ok  { padding:.75rem 1rem; border-radius:9px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.85rem; margin-bottom:1rem; }
.alert-err { padding:.75rem 1rem; border-radius:9px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.85rem; margin-bottom:1rem; }

@media(max-width:640px) {
  .metrics { grid-template-columns:1fr 1fr; }
  .actions-grid { grid-template-columns:1fr; }
}
</style>

<div class="club-wrap">

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
    <h1 style="font-size:1.25rem;font-weight:800;color:#0f172a;">⚡ <?= sanitize($rules['nome_clube'] ?? 'Clube For Men') ?> — Caixa</h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="club.php" style="padding:.45rem .9rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;font-weight:600;color:#475569;text-decoration:none;">📊 Painel</a>
        <a href="club_rules.php" style="padding:.45rem .9rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;font-weight:600;color:#475569;text-decoration:none;">⚙️ Regras</a>
        <a href="dashboard.php" style="padding:.45rem .9rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;font-weight:600;color:#475569;text-decoration:none;">← Dashboard</a>
    </div>
</div>

<!-- Busca cliente -->
<div class="club-search">
    <h2>🔍 Buscar Cliente pelo WhatsApp</h2>
    <form method="GET" class="search-row">
        <input type="text" name="w" class="search-inp" placeholder="Ex: 65999990000" value="<?= sanitize($whatsInput) ?>" autofocus inputmode="numeric">
        <button type="submit" class="search-btn">Buscar</button>
    </form>
</div>

<?php if ($msg):  ?><div class="alert-ok">✅ <?= $msg ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err">⚠️ <?= sanitize($erro) ?></div><?php endif; ?>

<?php if ($whatsInput && !$cliente): ?>
<div style="background:#fef9c3;border:1px solid #fde68a;border-radius:12px;padding:1.25rem;text-align:center;color:#92400e;">
    <p style="font-size:.95rem;font-weight:600;">Cliente não encontrado com este WhatsApp.</p>
    <p style="font-size:.8rem;margin-top:.35rem;">Cadastre o cliente em <a href="clients.php?action=create" style="color:#6366f1;font-weight:700;">Clientes → Novo Cliente</a> primeiro.</p>
</div>
<?php endif; ?>

<?php if ($cliente && $wallet && $stamp): ?>
<?php
$saldo     = (float)$wallet['saldo'];
$selosCiclo= (int)$stamp['selos_ciclo'];
$selosMeta = (int)$rules['selos_premio'];
$pct       = $selosMeta > 0 ? min(100, round($selosCiclo / $selosMeta * 100)) : 0;
?>

<!-- Card do cliente -->
<div class="client-card">
    <div class="client-hd">
        <div class="client-av"><?= strtoupper(substr($cliente['nome'],0,1)) ?></div>
        <div class="client-info">
            <h3><?= sanitize($cliente['nome']) ?></h3>
            <p><?= sanitize($cliente['whatsapp'] ?? '') ?> <?= !empty($cliente['email']) ? '· ' . sanitize($cliente['email']) : '' ?></p>
        </div>
        <div style="margin-left:auto;text-align:right;">
            <a href="clube.php?w=<?= urlencode($cliente['whatsapp'] ?? '') ?>" target="_blank"
               style="font-size:.72rem;color:#94a3b8;text-decoration:none;border:1px solid rgba(255,255,255,.15);padding:.25rem .6rem;border-radius:6px;">
                Ver página do cliente ↗
            </a>
        </div>
    </div>

    <!-- Métricas -->
    <div class="metrics">
        <div class="metric">
            <div class="val" style="color:<?= $saldo > 0 ? '#22c55e' : '#94a3b8' ?>;"><?= fmt_r($saldo) ?></div>
            <div class="lbl">Saldo Cashback</div>
        </div>
        <div class="metric">
            <div class="val" style="color:#f59e0b;"><?= $selosCiclo ?>/<?= $selosMeta ?></div>
            <div class="lbl">Selos (ciclo atual)</div>
        </div>
        <div class="metric">
            <div class="val" style="color:#6366f1;"><?= count($vouchers_ativos) ?></div>
            <div class="lbl">Vouchers ativos</div>
        </div>
    </div>

    <!-- Visual dos selos -->
    <div class="selos-row">
        <?php for ($i = 1; $i <= $selosMeta; $i++): ?>
        <div class="selo <?= $i <= $selosCiclo ? 'on' : '' ?>">
            <?= $i <= $selosCiclo ? '✂️' : '○' ?>
        </div>
        <?php endfor; ?>
        <?php if ($selosCiclo >= $selosMeta): ?>
        <div class="selo win">🎁</div>
        <?php endif; ?>
    </div>

    <!-- Vouchers ativos -->
    <?php if (!empty($vouchers_ativos)): ?>
    <div style="padding:.5rem 1.25rem 0;"><p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;">Vouchers disponíveis</p></div>
    <div class="voucher-list">
        <?php foreach ($vouchers_ativos as $v): ?>
        <div class="voucher-item">
            <div>
                <span class="voucher-code"><?= $v['codigo'] ?></span>
                <span style="margin-left:.5rem;font-size:.7rem;color:#92400e;"><?= ucfirst($v['tipo']) ?></span>
            </div>
            <div style="text-align:center;">
                <span class="voucher-val"><?= fmt_r($v['valor']) ?></span>
            </div>
            <div class="voucher-exp">Expira <?= date('d/m',strtotime($v['expira_em'])) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Ações -->
    <div class="actions-grid">

        <!-- Registrar Venda -->
        <div class="action-block" style="border-color:#bbf7d0;">
            <h4>💰 Registrar Venda (Cashback <?= $rules['cashback_pct'] ?>%)</h4>
            <form method="POST">
                <input type="hidden" name="acao" value="registrar_venda">
                <input type="hidden" name="whatsapp" value="<?= sanitize($whatsInput) ?>">
                <input type="number" name="valor_venda" class="a-inp" placeholder="Valor da venda R$" step="0.01" min="0" required>
                <button type="submit" class="a-btn green">+ Gerar Cashback</button>
            </form>
        </div>

        <!-- Resgatar Saldo -->
        <div class="action-block" style="border-color:#fca5a5;">
            <h4>🏷️ Resgatar Cashback (saldo: <?= fmt_r($saldo) ?>)</h4>
            <form method="POST">
                <input type="hidden" name="acao" value="resgatar_saldo">
                <input type="hidden" name="whatsapp" value="<?= sanitize($whatsInput) ?>">
                <input type="number" name="valor_resgate" class="a-inp" placeholder="Valor a resgatar R$" step="0.01" min="<?= $rules['resgate_minimo'] ?>" max="<?= $saldo ?>">
                <button type="submit" class="a-btn red" <?= $saldo < $rules['resgate_minimo'] ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>>
                    Resgatar Saldo
                </button>
            </form>
        </div>

        <!-- Adicionar Selo -->
        <div class="action-block" style="border-color:#fde68a;">
            <h4>✂️ Registrar Corte (<?= $selosCiclo ?>/<?= $selosMeta ?> selos)</h4>
            <form method="POST">
                <input type="hidden" name="acao" value="adicionar_selo">
                <input type="hidden" name="whatsapp" value="<?= sanitize($whatsInput) ?>">
                <input type="text" name="obs_selo" class="a-inp" placeholder="Obs: Degradê, Navalhado..." value="Corte registrado">
                <button type="submit" class="a-btn amber">+ Adicionar Selo</button>
            </form>
        </div>

        <!-- Voucher Turbinado -->
        <div class="action-block" style="border-color:#c7d2fe;">
            <h4>⚡ Voucher Turbinado (válido 24h)</h4>
            <form method="POST">
                <input type="hidden" name="acao" value="voucher_turbinado">
                <input type="hidden" name="whatsapp" value="<?= sanitize($whatsInput) ?>">
                <input type="number" name="valor_turbinado" class="a-inp" placeholder="Valor R$" step="0.01" value="<?= $rules['voucher_turbinado'] ?>">
                <input type="text" name="obs_turbinado" class="a-inp" placeholder="Motivo (ex: lançamento tênis)">
                <button type="submit" class="a-btn indigo">⚡ Gerar Turbinado</button>
            </form>
        </div>

    </div><!-- /actions-grid -->

    <!-- Usar voucher -->
    <div style="padding:0 1.25rem 1.25rem;">
        <div class="action-block" style="border-color:#e2e8f0;">
            <h4>🎟️ Aplicar Voucher</h4>
            <form method="POST" style="display:flex;gap:.5rem;">
                <input type="hidden" name="acao" value="usar_voucher">
                <input type="hidden" name="whatsapp" value="<?= sanitize($whatsInput) ?>">
                <input type="text" name="voucher_codigo" class="a-inp" placeholder="Código do voucher" style="text-transform:uppercase;margin-bottom:0;flex:1;">
                <button type="submit" class="a-btn green" style="width:auto;padding:.5rem 1rem;">Aplicar</button>
            </form>
        </div>
    </div>

    <!-- Histórico -->
    <?php if (!empty($historico)): ?>
    <div style="padding:0 1.25rem .5rem;"><p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;">Últimas movimentações</p></div>
    <div class="hist-list">
        <?php foreach ($historico as $h):
            $tc = in_array($h['tipo'],['credito','voucher_selos','voucher_turbinado']) ? 'c' : ($h['tipo']==='debito'?'d':'e');
            $icon = ['credito'=>'💰','debito'=>'🏷️','expiracao'=>'⏰','voucher_selos'=>'🎁','voucher_turbinado'=>'⚡'][$h['tipo']] ?? '•';
        ?>
        <div class="hist-item">
            <div class="hist-tipo <?= $tc ?>"><?= $icon ?></div>
            <div class="hist-desc"><?= sanitize($h['descricao'] ?? $h['tipo']) ?></div>
            <div class="hist-val <?= $tc ?>"><?= $tc==='c'?'+':'-' ?><?= fmt_r($h['valor']) ?></div>
            <div class="hist-date"><?= date('d/m H:i',strtotime($h['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /client-card -->
<?php endif; ?>

</div><!-- /club-wrap -->

<?php include __DIR__ . '/views/partials/footer.php'; ?>