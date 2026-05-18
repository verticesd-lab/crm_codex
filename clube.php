<?php
// ============================================================
// clube.php — Página pública do cliente
// Acesso: clube.php?w=65999990000
// O cliente vê saldo, selos e vouchers sem precisar logar
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = get_pdo();

// ── Identifica empresa pelo slug ou company_id da URL ─────────
$slug = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');
$whatsRaw = trim($_GET['w'] ?? '');
$whats    = preg_replace('/\D+/', '', $whatsRaw);

$company = null;
if ($slug) {
    $s = $pdo->prepare("SELECT * FROM companies WHERE slug=? LIMIT 1");
    $s->execute([$slug]);
    $company = $s->fetch(PDO::FETCH_ASSOC);
}
// Fallback: usa a primeira empresa (ambiente mono-empresa)
if (!$company) {
    $company = $pdo->query("SELECT * FROM companies ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
if (!$company) { echo '404'; exit; }

$companyId = (int)$company['id'];

// ── Regras do clube ───────────────────────────────────────────
$rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
$rules->execute([$companyId]);
$rules = $rules->fetch(PDO::FETCH_ASSOC);
if (!$rules) { $rules = ['nome_clube'=>'Clube For Men','cashback_pct'=>5,'cashback_validade'=>45,'selos_premio'=>4,'voucher_valor'=>50]; }

$nomeclube = $rules['nome_clube'] ?? 'Clube For Men';

// ── Busca cliente ─────────────────────────────────────────────
$cliente = null;
$wallet  = null;
$stamp   = null;
$vouchers= [];

if (strlen($whats) >= 8) {
    $s = $pdo->prepare("SELECT * FROM clients WHERE company_id=? AND whatsapp LIKE ? LIMIT 1");
    $s->execute([$companyId, '%' . substr($whats,-8)]);
    $cliente = $s->fetch(PDO::FETCH_ASSOC);
}

if ($cliente) {
    $cid = (int)$cliente['id'];

    $s = $pdo->prepare("SELECT * FROM club_wallets WHERE company_id=? AND client_id=? LIMIT 1");
    $s->execute([$companyId,$cid]);
    $wallet = $s->fetch(PDO::FETCH_ASSOC);

    $s = $pdo->prepare("SELECT * FROM club_stamps WHERE company_id=? AND client_id=? LIMIT 1");
    $s->execute([$companyId,$cid]);
    $stamp = $s->fetch(PDO::FETCH_ASSOC);

    $s = $pdo->prepare("SELECT * FROM club_vouchers WHERE company_id=? AND client_id=? AND usado=0 AND expira_em>=CURDATE() ORDER BY expira_em ASC");
    $s->execute([$companyId,$cid]);
    $vouchers = $s->fetchAll(PDO::FETCH_ASSOC);
}

$saldo      = (float)($wallet['saldo'] ?? 0);
$selosCiclo = (int)($stamp['selos_ciclo'] ?? 0);
$selosMeta  = (int)$rules['selos_premio'];
$pct        = $selosMeta > 0 ? min(100, round($selosCiclo / $selosMeta * 100)) : 0;
$logo       = $company['logo'] ?? '';
$whatsLoja  = preg_replace('/\D+/','',$company['whatsapp_principal'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= sanitize($nomeclube) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0a0a0a;--bg2:#111;--bg3:#1a1a1a;--border:#222;--gold:#f5c518;--green:#22c55e;--red:#ef4444;--purple:#6366f1;--white:#f0ede8;--gray:#666}
body{background:var(--bg);color:var(--white);font-family:'Space Grotesk',sans-serif;min-height:100vh;padding:0 0 80px}

/* Header */
.club-header{background:linear-gradient(160deg,#0f0f0f,#1a0f2e);border-bottom:1px solid var(--border);padding:24px 20px;text-align:center;position:relative}
.club-logo{width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--border);margin:0 auto 12px;display:block}
.club-logo-ph{width:64px;height:64px;border-radius:50%;background:var(--purple);display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:800;color:#fff;margin:0 auto 12px;border:2px solid rgba(99,102,241,.4)}
.club-nome{font-size:11px;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:var(--gray);margin-bottom:4px}
.club-title{font-size:22px;font-weight:800;color:var(--white)}

/* Busca */
.search-box{max-width:480px;margin:24px auto 0;padding:0 20px}
.search-box form{display:flex;gap:8px}
.search-box input{flex:1;padding:12px 14px;background:var(--bg2);border:1.5px solid var(--border);border-radius:10px;color:var(--white);font-size:16px;font-family:inherit;outline:none}
.search-box input:focus{border-color:var(--purple)}
.search-box button{padding:12px 18px;background:var(--purple);color:#fff;border:none;border-radius:10px;font-weight:700;font-size:14px;cursor:pointer}

/* Wrapper */
.content{max-width:480px;margin:0 auto;padding:20px}

/* Saudação */
.greeting{text-align:center;margin-bottom:20px}
.greeting h2{font-size:20px;font-weight:800}
.greeting p{font-size:13px;color:var(--gray);margin-top:4px}

/* Cards */
.card{background:var(--bg2);border:1px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:14px}
.card-hd{padding:14px 16px 10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.card-hd .icon{font-size:16px}
.card-hd h3{font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--gray)}
.card-body{padding:16px}

/* Saldo */
.saldo-big{font-size:48px;font-weight:800;color:var(--gold);line-height:1;text-align:center}
.saldo-sub{font-size:12px;color:var(--gray);text-align:center;margin-top:6px}
.saldo-bar-wrap{margin-top:14px}
.saldo-bar-lbl{display:flex;justify-content:space-between;font-size:11px;color:var(--gray);margin-bottom:6px}
.saldo-bar-bg{height:6px;background:var(--bg3);border-radius:99px;overflow:hidden}
.saldo-bar-fill{height:100%;background:var(--green);border-radius:99px}

/* Selos */
.selos-grid{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:12px}
.selo{width:52px;height:52px;border-radius:50%;border:2px solid var(--border);background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:1.3rem;transition:.2s}
.selo.on{border-color:#f59e0b;background:rgba(245,158,11,.12)}
.selos-info{text-align:center;font-size:13px;color:var(--gray)}
.selos-info strong{color:var(--white)}

/* Vouchers */
.voucher{background:rgba(245,197,24,.08);border:1px solid rgba(245,197,24,.25);border-radius:12px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.voucher-code{font-family:monospace;font-size:20px;font-weight:800;color:var(--gold);letter-spacing:2px}
.voucher-tipo{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--gray);margin-top:2px}
.voucher-val{font-size:24px;font-weight:800;color:var(--green);white-space:nowrap}
.voucher-exp{font-size:10px;color:var(--gray);margin-top:2px;text-align:right}

/* Não encontrado */
.not-found{text-align:center;padding:40px 20px;color:var(--gray)}
.not-found p{font-size:14px;line-height:1.6}

/* CTA Whats */
.whats-cta{display:flex;align-items:center;justify-content:center;gap:8px;background:#25d366;color:#000;font-weight:700;font-size:14px;padding:14px;border-radius:12px;text-decoration:none;margin-top:6px}
.whats-cta:hover{background:#1da851}

/* Badge */
.badge-new{display:inline-block;background:var(--purple);color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;letter-spacing:.5px;vertical-align:middle;margin-left:6px}

/* Info card */
.info-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);font-size:13px}
.info-row:last-child{border-bottom:none}
.info-row .lbl{color:var(--gray)}
.info-row .val{font-weight:700;color:var(--white)}

/* Empty */
.empty{text-align:center;padding:20px;color:var(--gray);font-size:13px}
</style>
</head>
<body>

<!-- Header -->
<div class="club-header">
    <?php if ($logo): ?>
        <img src="<?= sanitize(BASE_URL . '/' . ltrim($logo,'/')) ?>" class="club-logo" alt="Logo">
    <?php else: ?>
        <div class="club-logo-ph"><?= strtoupper(substr($company['nome_fantasia']??'C',0,1)) ?></div>
    <?php endif; ?>
    <p class="club-nome"><?= sanitize($company['nome_fantasia'] ?? '') ?></p>
    <p class="club-title">⚡ <?= sanitize($nomeclube) ?></p>
</div>

<!-- Busca pelo WhatsApp -->
<div class="search-box">
    <form method="GET">
        <?php if ($slug): ?><input type="hidden" name="empresa" value="<?= sanitize($slug) ?>"><?php endif; ?>
        <input type="tel" name="w" placeholder="Seu WhatsApp (só números)"
               value="<?= sanitize($whatsRaw) ?>" inputmode="numeric" autocomplete="tel">
        <button type="submit">Ver</button>
    </form>
</div>

<div class="content">

<?php if (!$cliente && $whats): ?>
<!-- Não encontrado -->
<div class="card" style="margin-top:8px;">
    <div class="card-body not-found">
        <p style="font-size:32px;margin-bottom:12px;">🤔</p>
        <p>Número não encontrado no <?= sanitize($nomeclube) ?>.</p>
        <p style="margin-top:8px;">Faça sua primeira compra ou corte de cabelo e peça para ser cadastrado!</p>
        <?php if ($whatsLoja): ?>
        <a href="https://wa.me/<?= $whatsLoja ?>?text=<?= urlencode('Olá! Quero me cadastrar no ' . $nomeclube) ?>"
           class="whats-cta" style="margin-top:16px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.117 1.526 5.845L0 24l6.334-1.498A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.896 0-3.67-.505-5.2-1.386l-.374-.217-3.758.888.928-3.637-.243-.388A9.96 9.96 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
            Falar com a loja
        </a>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($cliente): ?>

<!-- Saudação -->
<div class="greeting" style="margin-top:16px;">
    <h2>Olá, <?= sanitize(explode(' ',$cliente['nome'])[0]) ?>! 👋</h2>
    <p>Veja seus benefícios no <?= sanitize($nomeclube) ?></p>
</div>

<!-- Card de saldo -->
<div class="card">
    <div class="card-hd"><span class="icon">💰</span><h3>Saldo Cashback</h3></div>
    <div class="card-body">
        <div class="saldo-big"><?= 'R$' . number_format($saldo,2,',','.') ?></div>
        <p class="saldo-sub">
            <?php if ($saldo > 0): ?>
                Disponível para usar nas próximas compras!
            <?php else: ?>
                Faça uma compra e ganhe <?= $rules['cashback_pct'] ?>% de volta.
            <?php endif; ?>
        </p>
        <?php if ($saldo > 0): ?>
        <div class="saldo-bar-wrap">
            <div class="saldo-bar-lbl">
                <span>Saldo atual</span>
                <span>Expira em <?= $rules['cashback_validade'] ?> dias</span>
            </div>
            <div class="saldo-bar-bg">
                <div class="saldo-bar-fill" style="width:100%"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Card de selos -->
<div class="card">
    <div class="card-hd"><span class="icon">✂️</span><h3>Selos da Barbearia</h3></div>
    <div class="card-body">
        <div class="selos-grid">
            <?php for ($i=1;$i<=$selosMeta;$i++): ?>
            <div class="selo <?= $i<=$selosCiclo?'on':'' ?>">
                <?= $i<=$selosCiclo ? '✂️' : '○' ?>
            </div>
            <?php endfor; ?>
        </div>
        <p class="selos-info">
            <?php if ($selosCiclo >= $selosMeta): ?>
                <strong>🎉 Você desbloqueou um voucher!</strong> Mostre para o atendente.
            <?php elseif ($selosCiclo > 0): ?>
                <strong><?= $selosCiclo ?>/<?= $selosMeta ?></strong> — faltam <?= $selosMeta-$selosCiclo ?> cortes para ganhar <strong>R$<?= number_format($rules['voucher_valor'],2,',','.') ?></strong>
            <?php else: ?>
                Cada corte = 1 selo. <?= $selosMeta ?> selos = voucher de R$<?= number_format($rules['voucher_valor'],2,',','.') ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- Vouchers ativos -->
<?php if (!empty($vouchers)): ?>
<div class="card">
    <div class="card-hd">
        <span class="icon">🎟️</span>
        <h3>Vouchers Disponíveis <span class="badge-new"><?= count($vouchers) ?></span></h3>
    </div>
    <div class="card-body">
        <?php foreach ($vouchers as $v): ?>
        <div class="voucher">
            <div>
                <div class="voucher-code"><?= $v['codigo'] ?></div>
                <div class="voucher-tipo"><?= $v['tipo']==='selos'?'Prêmio Barbearia':($v['tipo']==='turbinado'?'Voucher Especial':'Manual') ?></div>
            </div>
            <div style="text-align:right;">
                <div class="voucher-val">R$<?= number_format($v['valor'],2,',','.') ?></div>
                <div class="voucher-exp">Expira <?= date('d/m/Y',strtotime($v['expira_em'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <p style="font-size:11px;color:var(--gray);text-align:center;margin-top:4px;">Mostre o código para o atendente ao comprar</p>
    </div>
</div>
<?php endif; ?>

<!-- Como funciona -->
<div class="card">
    <div class="card-hd"><span class="icon">ℹ️</span><h3>Como Funciona</h3></div>
    <div class="card-body">
        <div class="info-row">
            <span class="lbl">Cashback nas compras</span>
            <span class="val"><?= $rules['cashback_pct'] ?>% de volta</span>
        </div>
        <div class="info-row">
            <span class="lbl">Validade do cashback</span>
            <span class="val"><?= $rules['cashback_validade'] ?> dias</span>
        </div>
        <div class="info-row">
            <span class="lbl">Selos para prêmio</span>
            <span class="val"><?= $rules['selos_premio'] ?> cortes</span>
        </div>
        <div class="info-row">
            <span class="lbl">Prêmio da barbearia</span>
            <span class="val">R$<?= number_format($rules['voucher_valor'],2,',','.') ?> na loja</span>
        </div>
    </div>
</div>

<!-- CTA WhatsApp -->
<?php if ($whatsLoja): ?>
<a href="https://wa.me/<?= $whatsLoja ?>?text=<?= urlencode('Olá! Quero verificar meu saldo no ' . $nomeclube) ?>"
   class="whats-cta">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.117 1.526 5.845L0 24l6.334-1.498A11.95 11.95 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.896 0-3.67-.505-5.2-1.386l-.374-.217-3.758.888.928-3.637-.243-.388A9.96 9.96 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/></svg>
    Falar com a loja
</a>
<?php endif; ?>

<?php else: ?>
<!-- Sem WhatsApp informado -->
<div class="card" style="margin-top:16px;">
    <div class="card-body" style="text-align:center;padding:32px 20px;">
        <p style="font-size:32px;margin-bottom:12px;">⚡</p>
        <p style="font-size:15px;font-weight:700;color:var(--white);">Bem-vindo ao <?= sanitize($nomeclube) ?>!</p>
        <p style="font-size:13px;color:var(--gray);margin-top:8px;line-height:1.6;">
            Digite seu WhatsApp acima para ver seu saldo, selos e vouchers.
        </p>
    </div>
</div>
<?php endif; ?>

</div><!-- /content -->
</body>
</html>