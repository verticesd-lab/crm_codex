<?php
// ============================================================
// club_rules.php — Configurações do Clube For Men
// ============================================================
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

// Garante que existe regra
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo->prepare("
        UPDATE club_rules SET
            nome_clube        = ?,
            ativo             = ?,
            cashback_pct      = ?,
            cashback_validade = ?,
            cashback_minimo   = ?,
            resgate_minimo    = ?,
            selos_premio      = ?,
            voucher_valor     = ?,
            voucher_validade  = ?,
            voucher_turbinado = ?,
            updated_at        = NOW()
        WHERE company_id = ?
    ")->execute([
        trim($_POST['nome_clube']        ?? 'Clube For Men'),
        isset($_POST['ativo']) ? 1 : 0,
        (float)str_replace(',','.',$_POST['cashback_pct']      ?? '5'),
        (int)($_POST['cashback_validade'] ?? 45),
        (float)str_replace(',','.',$_POST['cashback_minimo']   ?? '0'),
        (float)str_replace(',','.',$_POST['resgate_minimo']    ?? '10'),
        (int)($_POST['selos_premio']      ?? 4),
        (float)str_replace(',','.',$_POST['voucher_valor']     ?? '50'),
        (int)($_POST['voucher_validade']  ?? 30),
        (float)str_replace(',','.',$_POST['voucher_turbinado'] ?? '100'),
        $companyId,
    ]);
    flash('success','Regras do clube atualizadas!');
    redirect('club_rules.php');
}

$flashSuccess = get_flash('success');

include __DIR__ . '/views/partials/header.php';
?>
<style>
.rules-wrap { max-width:680px; }
.rules-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.rules-hd { padding:.85rem 1.25rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; }
.rules-hd h2 { font-size:.9rem; font-weight:700; color:#0f172a; }
.rules-hd p  { font-size:.72rem; color:#94a3b8; margin-top:.1rem; }
.rules-body { padding:1.25rem; display:grid; grid-template-columns:1fr 1fr; gap:.85rem; }
.rules-body.full { grid-template-columns:1fr; }
.field label { display:block; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#64748b; margin-bottom:.3rem; }
.fi { width:100%; padding:.55rem .9rem; border:1.5px solid #e2e8f0; border-radius:9px; font-size:.88rem; color:#0f172a; background:#f8fafc; outline:none; }
.fi:focus { border-color:#6366f1; background:#fff; }
.fi-desc { font-size:.68rem; color:#94a3b8; margin-top:.25rem; }
.tog-row { display:flex; align-items:center; gap:.6rem; grid-column:1/-1; }
.tog { position:relative; width:44px; height:24px; }
.tog input { opacity:0; width:0; height:0; position:absolute; }
.tog-sl { position:absolute; inset:0; background:#e2e8f0; border-radius:24px; cursor:pointer; transition:.2s; }
.tog-sl::after { content:''; position:absolute; width:18px; height:18px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; }
.tog input:checked + .tog-sl { background:#22c55e; }
.tog input:checked + .tog-sl::after { left:23px; }
.btn-save { background:#6366f1; color:#fff; border:none; border-radius:9px; padding:.7rem 1.5rem; font-size:.88rem; font-weight:700; cursor:pointer; width:100%; }
.btn-save:hover { background:#4f46e5; }
.flash-ok { padding:.7rem 1rem; border-radius:9px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.82rem; margin-bottom:1rem; }
.section-sep { grid-column:1/-1; border-top:1px solid #f1f5f9; padding-top:.75rem; }
.section-sep p { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6366f1; }
@media(max-width:540px) { .rules-body { grid-template-columns:1fr; } }
</style>

<div class="rules-wrap">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
    <h1 style="font-size:1.25rem;font-weight:800;color:#0f172a;">⚙️ Regras do <?= sanitize($rules['nome_clube']) ?></h1>
    <div style="display:flex;gap:.5rem;">
        <a href="club_cashier.php" style="padding:.45rem .9rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;font-weight:600;color:#475569;text-decoration:none;">🧾 Caixa</a>
        <a href="club.php" style="padding:.45rem .9rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.78rem;font-weight:600;color:#475569;text-decoration:none;">📊 Painel</a>
    </div>
</div>

<?php if ($flashSuccess): ?><div class="flash-ok">✅ <?= sanitize($flashSuccess) ?></div><?php endif; ?>

<form method="POST">
    <!-- Geral -->
    <div class="rules-card">
        <div class="rules-hd"><h2>🏷️ Identidade do Clube</h2></div>
        <div class="rules-body">
            <div class="field" style="grid-column:1/-1;">
                <label>Nome do Clube</label>
                <input type="text" name="nome_clube" class="fi" value="<?= sanitize($rules['nome_clube']) ?>" placeholder="Clube For Men">
            </div>
            <div class="tog-row">
                <label class="tog">
                    <input type="checkbox" name="ativo" value="1" <?= $rules['ativo']?'checked':'' ?>>
                    <span class="tog-sl"></span>
                </label>
                <span style="font-size:.85rem;color:#374151;font-weight:500;">Clube ativo</span>
            </div>
        </div>
    </div>

    <!-- Cashback -->
    <div class="rules-card">
        <div class="rules-hd">
            <h2>💰 Cashback (Loja de Roupas)</h2>
            <p>% sobre compras na loja, com validade para criar urgência de retorno</p>
        </div>
        <div class="rules-body">
            <div class="field">
                <label>% de Cashback</label>
                <input type="number" name="cashback_pct" class="fi" step="0.5" min="0" max="50" value="<?= $rules['cashback_pct'] ?>">
                <p class="fi-desc">Ex: 5 = 5% sobre o valor da compra</p>
            </div>
            <div class="field">
                <label>Validade (dias)</label>
                <input type="number" name="cashback_validade" class="fi" min="1" max="365" value="<?= $rules['cashback_validade'] ?>">
                <p class="fi-desc">Recomendado: 45 dias (cria urgência)</p>
            </div>
            <div class="field">
                <label>Compra mínima para gerar (R$)</label>
                <input type="number" name="cashback_minimo" class="fi" step="0.01" min="0" value="<?= $rules['cashback_minimo'] ?>">
                <p class="fi-desc">0 = qualquer valor gera cashback</p>
            </div>
            <div class="field">
                <label>Saldo mínimo para resgatar (R$)</label>
                <input type="number" name="resgate_minimo" class="fi" step="0.01" min="0" value="<?= $rules['resgate_minimo'] ?>">
                <p class="fi-desc">Ex: 10 = mínimo R$10 acumulado</p>
            </div>
        </div>
    </div>

    <!-- Selos -->
    <div class="rules-card">
        <div class="rules-hd">
            <h2>✂️ Selos da Barbearia</h2>
            <p>Cada corte = 1 selo. Ao completar, o cliente ganha voucher OBRIGATÓRIO na loja de roupas</p>
        </div>
        <div class="rules-body">
            <div class="field">
                <label>Cortes para ganhar prêmio</label>
                <input type="number" name="selos_premio" class="fi" min="2" max="20" value="<?= $rules['selos_premio'] ?>">
                <p class="fi-desc">Recomendado: 4 cortes</p>
            </div>
            <div class="field">
                <label>Valor do voucher (R$)</label>
                <input type="number" name="voucher_valor" class="fi" step="0.01" min="0" value="<?= $rules['voucher_valor'] ?>">
                <p class="fi-desc">Ex: 50 = R$50 na loja de roupas</p>
            </div>
            <div class="field">
                <label>Validade do voucher (dias)</label>
                <input type="number" name="voucher_validade" class="fi" min="1" max="90" value="<?= $rules['voucher_validade'] ?>">
                <p class="fi-desc">Após ganhar, quantos dias para usar</p>
            </div>
        </div>
    </div>

    <!-- Turbinado -->
    <div class="rules-card">
        <div class="rules-hd">
            <h2>⚡ Voucher Turbinado</h2>
            <p>Valor padrão do voucher gerado manualmente no caixa (válido por 24h)</p>
        </div>
        <div class="rules-body rules-full">
            <div class="field">
                <label>Valor padrão do turbinado (R$)</label>
                <input type="number" name="voucher_turbinado" class="fi" step="0.01" min="0" value="<?= $rules['voucher_turbinado'] ?>">
                <p class="fi-desc">O funcionário pode alterar na hora — este é só o valor pré-preenchido</p>
            </div>
        </div>
    </div>

    <button type="submit" class="btn-save">💾 Salvar Regras do Clube</button>
</form>
</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>