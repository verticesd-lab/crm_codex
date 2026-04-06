<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

if (!$companyId) {
    flash('error', 'Empresa não definida na sessão.');
    redirect('dashboard.php');
}

/* ══════════════════════════════════════════════════════════════
   SETUP DE TABELAS — feito no PHP antes de qualquer render.
   Assim as colunas já existem quando o JS chamar os endpoints.
══════════════════════════════════════════════════════════════ */
try {
    $clientCols = $pdo->query('SHOW COLUMNS FROM clients')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reativ_status',       $clientCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN reativ_status VARCHAR(30) DEFAULT 'elegivel'");
    if (!in_array('reativ_ultimo_envio', $clientCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN reativ_ultimo_envio DATETIME NULL");
    if (!in_array('reativ_tentativas',   $clientCols)) $pdo->exec("ALTER TABLE clients ADD COLUMN reativ_tentativas TINYINT DEFAULT 0");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reativacao_lotes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL, criado_em DATETIME NOT NULL,
        iniciado_em DATETIME NULL, concluido_em DATETIME NULL,
        status VARCHAR(20) DEFAULT 'aguardando', contexto VARCHAR(20) DEFAULT 'misto',
        total_clientes INT DEFAULT 0, enviados INT DEFAULT 0, erros INT DEFAULT 0,
        mensagem_idx TINYINT DEFAULT 0, criado_por INT UNSIGNED NULL, observacoes TEXT NULL,
        INDEX idx_company (company_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reativacao_envios (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lote_id INT UNSIGNED NOT NULL, client_id INT UNSIGNED NOT NULL,
        company_id INT UNSIGNED NOT NULL, whatsapp VARCHAR(30) NOT NULL,
        nome VARCHAR(120) NOT NULL, contexto VARCHAR(20) DEFAULT 'whatsapp',
        mensagem TEXT NOT NULL, tentativa TINYINT DEFAULT 1,
        status VARCHAR(20) DEFAULT 'pendente',
        enviado_em DATETIME NULL, respondeu_em DATETIME NULL, erro_msg TEXT NULL,
        INDEX idx_lote (lote_id), INDEX idx_client (client_id),
        INDEX idx_company (company_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

/* ── KPIs para o dashboard ── */
$policy = reactivation_policy();
$stats = ['total'=>0,'elegiveis'=>0,'responderam'=>0,'aguardando'=>0,'standby'=>0,'sem_resposta'=>0,'lote_ativo'=>null,'pode_enviar'=>true,'pode_enviar_em'=>null,'cooldown_reason'=>null,'daily_lotes_used'=>0,'daily_contacts_used'=>0,'remaining_lotes'=>$policy['max_lotes_per_day'],'remaining_contacts'=>$policy['max_contacts_per_day']];
try {
    $rows = $pdo->prepare("SELECT COALESCE(reativ_status,'elegivel') as s, COUNT(*) as n FROM clients WHERE company_id=? AND whatsapp IS NOT NULL AND whatsapp != '' GROUP BY s");
    $rows->execute([$companyId]);
    $by = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['s']] = (int)$r['n'];
    $stats['total']        = array_sum($by);
    $stats['elegiveis']    = ($by['elegivel']??0) + ($by['']??0);
    $stats['responderam']  = ($by['respondeu_1']??0) + ($by['respondeu_2']??0);
    $stats['aguardando']   = ($by['aguardando_2']??0) + ($by['lote_enviado_1']??0) + ($by['lote_enviado_2']??0);
    $stats['standby']      = $by['standby'] ?? 0;
    $stats['sem_resposta'] = $by['sem_resposta'] ?? 0;

    $la = $pdo->prepare("SELECT id,status,total_clientes,enviados,erros FROM reativacao_lotes WHERE company_id=? AND status IN ('aguardando','em_andamento') ORDER BY criado_em DESC LIMIT 1");
    $la->execute([$companyId]);
    $stats['lote_ativo'] = $la->fetch(PDO::FETCH_ASSOC) ?: null;

    $availability = reactivation_availability($pdo, $companyId);
    $stats['pode_enviar']         = $availability['can_send'];
    $stats['pode_enviar_em']      = $availability['next_at_br'];
    $stats['cooldown_reason']     = $availability['reason'];
    $stats['daily_lotes_used']    = $availability['daily_lotes_used'];
    $stats['daily_contacts_used'] = $availability['daily_contacts_used'];
    $stats['remaining_lotes']     = $availability['remaining_lotes'];
    $stats['remaining_contacts']  = $availability['remaining_contacts'];
    if (false) {
        if ($prox > time()) { $stats['pode_enviar'] = false; $stats['pode_enviar_em'] = date('d/m/Y \à\s H:i', $prox); }
    }
} catch (Throwable $e) {}

include __DIR__ . '/views/partials/header.php';
if ($m = get_flash('success')) echo '<div class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">'.sanitize($m).'</div>';
if ($m = get_flash('error'))   echo '<div class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">'.sanitize($m).'</div>';
?>
<style>
.rv-tabs{display:flex;gap:.25rem;border-bottom:2px solid #e2e8f0;margin-bottom:1.5rem}
.rv-tab{padding:.65rem 1.25rem;font-size:.85rem;font-weight:600;color:#64748b;text-decoration:none;border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;border-radius:6px 6px 0 0;white-space:nowrap}
.rv-tab:hover{color:#6366f1;background:#f5f3ff}
.rv-tab.active{color:#6366f1;border-bottom-color:#6366f1;background:#f5f3ff}
.rv-panel{display:none}.rv-panel.active{display:block}
.rv-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem}
.rv-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem 1.25rem;position:relative;overflow:hidden}
.rv-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--c,#6366f1)}
.rv-kpi-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.4rem}
.rv-kpi-val{font-size:1.8rem;font-weight:800;color:#0f172a;line-height:1}
.rv-kpi-sub{font-size:.68rem;color:#94a3b8;margin-top:.25rem}
.rv-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:1.25rem}
.rv-card-hd{padding:.9rem 1.25rem;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.rv-card-hd h3{font-size:.9rem;font-weight:700;color:#0f172a}
.rv-card-bd{padding:1.25rem}
.funil-row{display:flex;align-items:center;gap:10px;margin-bottom:9px}
.funil-lbl{font-size:.78rem;color:#475569;width:160px;flex-shrink:0}
.funil-bw{flex:1;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden}
.funil-bar{height:100%;border-radius:4px;transition:width .6s ease}
.funil-n{font-size:.72rem;font-family:monospace;color:#0f172a;width:30px;text-align:right}
.lote-banner{background:#f5f3ff;border:1px solid #c7d2fe;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.rv-filters{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.1rem 1.25rem;display:flex;gap:.85rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem}
.rv-fg{display:flex;flex-direction:column;gap:.3rem}
.rv-fg label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
.rv-select{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;color:#0f172a;font-size:.82rem;padding:.55rem .8rem;outline:none;transition:border-color .15s;font-family:inherit}
.rv-select:focus{border-color:#6366f1}
.rv-sel-bar{background:#f5f3ff;border:1px solid #c7d2fe;border-radius:8px;padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;font-size:.82rem;color:#4338ca}
.rv-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
.rv-table{width:100%;border-collapse:collapse;font-size:.875rem}
.rv-table thead th{padding:.8rem 1rem;text-align:left;font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.rv-table tbody tr{border-bottom:1px solid #f8fafc;transition:background .1s}
.rv-table tbody tr:last-child{border-bottom:none}
.rv-table tbody tr:hover{background:#fafafe}
.rv-table td{padding:.8rem 1rem;vertical-align:middle}
.ctx-badge{display:inline-flex;align-items:center;gap:3px;font-size:.68rem;font-weight:600;padding:.2rem .55rem;border-radius:20px}
.ctx-pdv{background:#fef9c3;color:#a16207}.ctx-barbearia{background:#ede9fe;color:#6d28d9}.ctx-whatsapp{background:#dcfce7;color:#15803d}
.dias-badge{font-size:.72rem;padding:.2rem .5rem;border-radius:4px;font-family:monospace}
.dias-hot{background:#fee2e2;color:#dc2626}.dias-warm{background:#fef3c7;color:#d97706}.dias-cold{background:#f1f5f9;color:#64748b}
.rv-console{background:#0f172a;border:1px solid #1e293b;border-radius:12px;overflow:hidden}
.rv-console-hd{background:#1e293b;padding:.75rem 1rem;display:flex;align-items:center;gap:.5rem;font-size:.72rem;color:#94a3b8;font-family:monospace}
.rv-console-bd{padding:.85rem 1rem;max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:2px}
.log-line{display:flex;gap:.5rem;font-size:.72rem;font-family:monospace;padding:2px 0;border-bottom:1px solid rgba(255,255,255,.03)}
.log-time{color:#475569;flex-shrink:0}.log-ok{color:#22c55e;flex-shrink:0}.log-err{color:#ef4444;flex-shrink:0}.log-info{color:#94a3b8;flex-shrink:0}.log-text{color:#94a3b8;flex:1}
.dot-pulse{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.dot-pulse.on{background:#22c55e;animation:pulse 1.2s infinite}.dot-pulse.off{background:#475569}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.lote-row{display:grid;grid-template-columns:50px 1fr auto auto 110px 90px;align-items:center;gap:.75rem;padding:.9rem 1.1rem;border-bottom:1px solid #f8fafc;font-size:.82rem;transition:background .1s}
.lote-row:hover{background:#fafafe}.lote-row:last-child{border-bottom:none}
.st-badge{font-size:.68rem;font-weight:700;padding:.25rem .6rem;border-radius:20px}
.st-aguardando{background:#fef9c3;color:#a16207}.st-andamento{background:#ede9fe;color:#6d28d9}.st-concluido{background:#dcfce7;color:#15803d}.st-cancelado{background:#f1f5f9;color:#64748b}
.env-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.68rem;font-weight:700;padding:.25rem .55rem;border-radius:20px;white-space:nowrap}
.env-enviado{background:#dcfce7;color:#15803d}.env-erro{background:#fee2e2;color:#dc2626}.env-respondeu{background:#dbeafe;color:#1d4ed8}.env-pendente{background:#f8fafc;color:#64748b}
.lote-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1rem}
.lote-stat{border:1px solid #e2e8f0;border-radius:12px;padding:.85rem 1rem;background:#fff}
.lote-stat-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:.35rem}
.lote-stat-val{font-size:1.25rem;font-weight:800;color:#0f172a;line-height:1}
.lote-stat-sub{font-size:.72rem;color:#64748b;margin-top:.2rem}
.lote-client-cell{display:flex;flex-direction:column;gap:.18rem}
.lote-client-name{font-weight:700;color:#0f172a}
.lote-client-sub{font-size:.72rem;color:#64748b;font-family:monospace}
.lote-client-msg{font-size:.72rem;color:#64748b;line-height:1.4}
.lote-time-cell{display:flex;flex-direction:column;gap:.18rem;font-size:.72rem;color:#64748b}
.lote-empty-inline{padding:1.5rem 1rem;text-align:center;color:#94a3b8;font-size:.82rem}
.seg-btns{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem}
.seg-btn{padding:.4rem .9rem;border-radius:20px;font-size:.75rem;font-weight:600;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;cursor:pointer;transition:all .15s}
.seg-btn:hover{color:#6366f1}.seg-btn.active{background:#f5f3ff;border-color:#6366f1;color:#6366f1}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.1rem;border-radius:9px;font-size:.82rem;font-weight:600;cursor:pointer;border:none;font-family:inherit;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn-primary{background:#6366f1;color:#fff}.btn-primary:hover{background:#4f46e5}.btn-primary:disabled{opacity:.4;cursor:not-allowed}
.btn-green{background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0}.btn-green:hover{background:#dcfce7}
.btn-danger{background:#fef2f2;color:#dc2626;border:1.5px solid #fecaca}.btn-danger:hover{background:#fee2e2}
.btn-ghost{background:#f8fafc;color:#475569;border:1.5px solid #e2e8f0}.btn-ghost:hover{color:#0f172a;border-color:#94a3b8}
.btn-sm{padding:.4rem .75rem;font-size:.75rem}
.rv-modal-ov{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
.rv-modal-ov.open{display:flex}
.rv-modal{background:#fff;border-radius:16px;width:min(520px,95vw);max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.18);padding:1.75rem}
.rv-modal h2{font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:1.25rem}
.rv-msg-preview{background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #6366f1;border-radius:8px;padding:.85rem 1rem;font-size:.82rem;color:#475569;white-space:pre-line;font-style:italic;margin-bottom:1rem;line-height:1.6}
.rv-cfg-row{display:flex;justify-content:space-between;padding:.65rem 0;border-bottom:1px solid #f1f5f9;font-size:.82rem}
.rv-cfg-row:last-child{border-bottom:none}
.rv-cfg-lbl{color:#64748b}.rv-cfg-val{font-weight:600;font-family:monospace}
.rv-obs{width:100%;padding:.6rem .85rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;color:#0f172a;font-family:inherit;resize:none;outline:none;background:#f8fafc;transition:border-color .15s;margin-bottom:1.25rem}
.rv-obs:focus{border-color:#6366f1;background:#fff}
.rv-empty{text-align:center;padding:3rem 1rem;color:#94a3b8;font-size:.875rem}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:2px}

/* ── Message Cards ── */
.msg-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:1rem; overflow:hidden; transition:box-shadow .15s; }
.msg-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.07); }
.msg-card-hd { display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; gap:.75rem; flex-wrap:wrap; }
.msg-card-meta { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
.msg-tent-badge { font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:20px; }
.msg-tent-1 { background:#ede9fe; color:#6d28d9; }
.msg-tent-2 { background:#fef3c7; color:#d97706; }
.msg-var-badge { font-size:.65rem; font-weight:600; background:#f1f5f9; color:#64748b; padding:.15rem .45rem; border-radius:4px; font-family:monospace; }
.msg-custom-badge { font-size:.65rem; font-weight:700; background:#dcfce7; color:#15803d; padding:.15rem .45rem; border-radius:4px; }
.msg-card-bd { padding:1rem; display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
@media (max-width:700px) { .msg-card-bd { grid-template-columns:1fr; } }
.msg-editor { display:flex; flex-direction:column; gap:.4rem; }
.msg-editor label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
.msg-textarea { width:100%; padding:.65rem .85rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.82rem; color:#0f172a; font-family:inherit; resize:vertical; min-height:100px; outline:none; transition:border-color .15s, background .15s; background:#f8fafc; line-height:1.55; }
@media (max-width:900px){.lote-row{grid-template-columns:50px 1fr auto;}.lote-row > :nth-child(4),.lote-row > :nth-child(5){display:none}}
.msg-textarea:focus { border-color:#6366f1; background:#fff; }
.msg-textarea.modified { border-color:#f59e0b; background:#fffbeb; }
.msg-preview-box { background:#f0fdf4; border:1px solid #bbf7d0; border-left:3px solid #22c55e; border-radius:8px; padding:.75rem 1rem; font-size:.82rem; color:#166534; white-space:pre-line; line-height:1.55; min-height:100px; }
.msg-actions { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:.5rem; }
.btn-test { background:#f0fdf4; color:#16a34a; border:1.5px solid #bbf7d0; }
.btn-test:hover { background:#dcfce7; }
.btn-test:disabled { opacity:.4; cursor:not-allowed; }
.btn-reset { background:#fefce8; color:#a16207; border:1.5px solid #fde68a; }
.btn-reset:hover { background:#fef3c7; }
.ctx-section-hd { display:flex; align-items:center; gap:.75rem; padding:.65rem 0; margin-bottom:.75rem; border-bottom:2px solid #f1f5f9; }
.ctx-section-hd-icon { font-size:1.1rem; }
.ctx-section-hd h3 { font-size:.95rem; font-weight:700; color:#0f172a; }
.ctx-section-hd p { font-size:.72rem; color:#94a3b8; }

</style>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem">
  <div>
    <h1 style="font-size:1.4rem;font-weight:700;color:#0f172a">🔁 Reativação de Clientes</h1>
    <p style="font-size:.82rem;color:#64748b;margin-top:.15rem">Reconecte clientes inativos com cadência e controle total</p>
  </div>
  <div style="display:flex;align-items:center;gap:.5rem;font-size:.78rem;color:#64748b">
    <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;box-shadow:0 0 5px #22c55e"></span>
    Evolution API conectada
  </div>
</div>

<div class="rv-tabs">
  <button class="rv-tab active" data-tab="dashboard">📊 Dashboard</button>
  <button class="rv-tab" data-tab="criar">📤 Criar Lote</button>
  <button class="rv-tab" data-tab="lotes">📋 Histórico</button>
  <button class="rv-tab" data-tab="segmentos">🗂️ Segmentos</button>
  <button class="rv-tab" data-tab="pos_barbearia">✂️ Pós-Barbearia</button>
  <button class="rv-tab" data-tab="mensagens">📝 Mensagens</button>
</div>

<!-- ═══ DASHBOARD ═══ -->
<div id="tab-dashboard" class="rv-panel active">

  <?php if (!$stats['pode_enviar']): ?>
  <div style="background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:.85rem 1.25rem;font-size:.82rem;color:#a16207;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem">
    ⏱️ Cooldown ativo — próximo lote disponível em: <strong><?= $stats['pode_enviar_em'] ?></strong>
  </div>
  <?php endif; ?>

  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.75rem 1rem;font-size:.8rem;color:#1d4ed8;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap">
    <span>Hoje: <strong><?= (int)$stats['daily_lotes_used'] ?>/<?= (int)$policy['max_lotes_per_day'] ?></strong> lotes e <strong><?= (int)$stats['daily_contacts_used'] ?>/<?= (int)$policy['max_contacts_per_day'] ?></strong> contatos usados.</span>
    <span>Restam <strong><?= (int)$stats['remaining_lotes'] ?></strong> lotes e <strong><?= (int)$stats['remaining_contacts'] ?></strong> contatos hoje. Intervalo minimo: <strong>1h</strong>.</span>
  </div>

  <?php if ($stats['lote_ativo']): $la=$stats['lote_ativo']; $pct=$la['total_clientes']>0?round($la['enviados']/$la['total_clientes']*100):0; ?>
  <div class="lote-banner">
    <div>
      <div style="font-size:.875rem;font-weight:700;color:#4338ca;margin-bottom:.2rem">⚡ Lote em andamento</div>
      <div style="font-size:.75rem;color:#6366f1"><?= (int)$la['enviados'] ?>/<?= (int)$la['total_clientes'] ?> enviados · <?= (int)$la['erros'] ?> erros</div>
    </div>
    <div style="flex:1;max-width:180px">
      <div style="height:6px;background:#e0e7ff;border-radius:3px;overflow:hidden"><div style="height:100%;background:#6366f1;width:<?= $pct ?>%;transition:width .3s"></div></div>
      <div style="font-size:.68rem;color:#6366f1;margin-top:4px;text-align:right"><?= $pct ?>%</div>
    </div>
    <button class="btn btn-primary btn-sm" onclick="switchTab('lotes')">Ver lote →</button>
  </div>
  <?php endif; ?>

  <div class="rv-kpis">
    <div class="rv-kpi" style="--c:#94a3b8"><div class="rv-kpi-lbl">Base c/ WhatsApp</div><div class="rv-kpi-val"><?= number_format($stats['total'],0,'.','.') ?></div><div class="rv-kpi-sub">clientes</div></div>
    <div class="rv-kpi" style="--c:#6366f1"><div class="rv-kpi-lbl">Elegíveis</div><div class="rv-kpi-val"><?= number_format($stats['elegiveis'],0,'.','.') ?></div><div class="rv-kpi-sub">prontos para contato</div></div>
    <div class="rv-kpi" style="--c:#22c55e"><div class="rv-kpi-lbl">Responderam</div><div class="rv-kpi-val"><?= $stats['responderam'] ?></div><div class="rv-kpi-sub">1ª ou 2ª tentativa</div></div>
    <div class="rv-kpi" style="--c:#f59e0b"><div class="rv-kpi-lbl">Em espera</div><div class="rv-kpi-val"><?= $stats['aguardando'] ?></div><div class="rv-kpi-sub">enviado / aguardando</div></div>
    <div class="rv-kpi" style="--c:#ef4444"><div class="rv-kpi-lbl">Standby</div><div class="rv-kpi-val"><?= $stats['standby'] ?></div><div class="rv-kpi-sub">não pertuba mais</div></div>
  </div>

  <div class="rv-card">
    <div class="rv-card-hd"><h3>Funil de Reativação</h3></div>
    <div class="rv-card-bd">
      <?php $t=max($stats['total'],1);
      foreach([['Elegíveis',$stats['elegiveis'],'#6366f1'],['Aguardando / Enviado',$stats['aguardando'],'#f59e0b'],['Responderam ✅',$stats['responderam'],'#22c55e'],['Sem resposta',$stats['sem_resposta'],'#ef4444'],['Standby',$stats['standby'],'#94a3b8']] as [$l,$v,$c]): ?>
      <div class="funil-row">
        <span class="funil-lbl"><?= $l ?></span>
        <div class="funil-bw"><div class="funil-bar" style="width:<?= round($v/$t*100,1) ?>%;background:<?= $c ?>"></div></div>
        <span class="funil-n"><?= $v ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="switchTab('criar')">＋ Criar novo lote</button>
    <button class="btn btn-ghost" onclick="switchTab('segmentos')">🗂️ Ver segmentos</button>
    <button class="btn btn-ghost btn-sm" onclick="location.reload()" style="margin-left:auto">↻ Atualizar</button>
  </div>
</div>

<!-- ═══ CRIAR LOTE ═══ -->
<div id="tab-criar" class="rv-panel">
  <div style="margin-bottom:1.25rem">
    <h2 style="font-size:1rem;font-weight:700;color:#0f172a">Selecionar clientes para o lote</h2>
    <p style="font-size:.78rem;color:#64748b;margin-top:.15rem">Filtre, revise as mensagens e crie o lote para envio</p>
  </div>

  <div class="rv-filters">
    <div class="rv-fg"><label>Dias sem visita</label>
      <select class="rv-select" id="f-dias">
        <option value="30">Mais de 30 dias</option>
        <option value="60" selected>Mais de 60 dias</option>
        <option value="90">Mais de 90 dias</option>
        <option value="180">Mais de 180 dias</option>
      </select>
    </div>
    <div class="rv-fg"><label>Contexto</label>
      <select class="rv-select" id="f-contexto">
        <option value="todos">Todos</option>
        <option value="pdv">Loja física (PDV)</option>
        <option value="barbearia">Barbearia</option>
        <option value="whatsapp">Só WhatsApp</option>
      </select>
    </div>
    <div class="rv-fg"><label>Tentativa</label>
      <select class="rv-select" id="f-tentativa">
        <option value="1">1ª mensagem</option>
        <option value="2">2ª mensagem</option>
      </select>
    </div>
    <div class="rv-fg"><label>Tamanho</label>
      <select class="rv-select" id="f-limite">
        <option value="15">15 clientes</option>
        <option value="20" selected>20 clientes</option>
        <option value="30">30 clientes</option>
      </select>
    </div>
    <div class="rv-fg" style="justify-content:flex-end;padding-top:18px">
      <button class="btn btn-primary" onclick="loadEligible()">🔍 Buscar</button>
    </div>
  </div>

  <div id="rv-sel-bar" class="rv-sel-bar" style="display:none">
    <span><strong id="rv-sel-count">0</strong> clientes selecionados</span>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-ghost btn-sm" onclick="selectAll(true)">Todos</button>
      <button class="btn btn-ghost btn-sm" onclick="selectAll(false)">Limpar</button>
      <button class="btn btn-primary btn-sm" onclick="openModal()">Criar lote →</button>
    </div>
  </div>

  <div class="rv-table-wrap">
    <div id="rv-eligible-loading" class="rv-empty">Clique em <strong>Buscar</strong> para carregar os clientes elegíveis.</div>
    <table class="rv-table" id="rv-eligible-table" style="display:none">
      <thead><tr>
        <th style="width:36px"><input type="checkbox" id="check-all" onchange="toggleAll(this)"></th>
        <th>Nome</th><th>WhatsApp</th><th>Contexto</th><th>Sem visita</th><th>Prévia da mensagem</th>
      </tr></thead>
      <tbody id="rv-eligible-tbody"></tbody>
    </table>
  </div>
</div>

<!-- ═══ HISTÓRICO ═══ -->
<div id="tab-lotes" class="rv-panel">
  <div id="rv-send-panel" style="display:none;margin-bottom:1.5rem">
    <div class="rv-card">
      <div class="rv-card-hd">
        <h3>📡 Envio em andamento</h3>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:.5rem">
            <label style="font-size:.72rem;font-weight:600;color:#64748b;white-space:nowrap">Intervalo:</label>
<span style="font-size:.75rem;color:#6366f1;font-family:monospace;background:#ede9fe;padding:.2rem .6rem;border-radius:6px">🎲 180–455s aleatório</span>
            <span id="delay-val" style="font-size:.68rem;color:#94a3b8;font-family:monospace" id="delay-val"></span>
          </div>
          <button class="btn btn-green btn-sm" id="btn-start" onclick="startSending()">▶ Iniciar</button>
          <button class="btn btn-ghost btn-sm" id="btn-stop" onclick="stopSending()" style="display:none">⏸ Pausar</button>
          <button class="btn btn-danger btn-sm" onclick="cancelLote()">✕ Cancelar lote</button>
        </div>
      </div>
      <!-- Barra de progresso -->
      <div id="rv-progress-wrap" style="margin-bottom:.85rem;display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem">
          <div style="display:flex;align-items:center;gap:.6rem">
            <div class="dot-pulse off" id="dot-pulse"></div>
            <span id="console-status" style="font-size:.82rem;font-weight:600;color:#0f172a">Aguardando início</span>
          </div>
          <div style="display:flex;align-items:center;gap:.75rem">
            <span id="prog-fraction" style="font-size:.78rem;font-weight:700;color:#6366f1;font-family:monospace">0 / 0</span>
            <span id="prog-pct" style="font-size:.78rem;font-weight:700;color:#6366f1;font-family:monospace;min-width:36px;text-align:right">0%</span>
          </div>
        </div>
        <div style="height:10px;background:#e0e7ff;border-radius:99px;overflow:hidden;position:relative">
          <div id="prog-bar" style="height:100%;background:linear-gradient(90deg,#6366f1,#818cf8);border-radius:99px;width:0%;transition:width .5s ease;position:relative">
            <div style="position:absolute;top:0;right:0;bottom:0;width:40px;background:linear-gradient(90deg,transparent,rgba(255,255,255,.35));border-radius:99px"></div>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:.35rem">
          <span id="prog-ok"  style="font-size:.68rem;color:#16a34a">✓ 0 enviados</span>
          <span id="prog-err" style="font-size:.68rem;color:#dc2626">✗ 0 erros</span>
          <span id="prog-next-wrap" style="font-size:.68rem;color:#64748b">próximo em <span id="prog-next" style="font-family:monospace;font-weight:700;color:#6366f1">—</span></span>
        </div>
      </div>
      <!-- Console -->
      <div class="rv-console">
        <div class="rv-console-hd" id="rv-console-hd-simple" style="">
          <div class="dot-pulse off" id="dot-pulse-simple" style="display:none"></div>
          <span style="font-size:.68rem;color:#94a3b8">Log de envios</span>
          <span style="margin-left:auto;font-size:.68rem" id="console-prog"></span>
        </div>
        <div class="rv-console-bd" id="console-log">
          <div class="log-line"><span class="log-info">›</span><span class="log-text" style="margin-left:.3rem">Console inicializado. Configure o intervalo e clique em Iniciar.</span></div>
        </div>
      </div>
    </div>
  </div>

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
    <h2 style="font-size:1rem;font-weight:700;color:#0f172a">Histórico de Lotes</h2>
    <button class="btn btn-ghost btn-sm" onclick="loadLotes()">↻ Atualizar</button>
  </div>
  <div id="rv-lote-detail-card" class="rv-card" style="display:none">
    <div class="rv-card-hd">
      <div>
        <h3 id="rv-lote-detail-title">Detalhes do lote</h3>
        <div id="rv-lote-detail-sub" style="font-size:.75rem;color:#64748b;margin-top:.2rem"></div>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="closeLoteDetail()">Fechar</button>
    </div>
    <div class="rv-card-bd">
      <div class="lote-detail-grid" id="rv-lote-detail-stats"></div>
      <div class="rv-table-wrap">
        <table class="rv-table">
          <thead>
            <tr>
              <th>Cliente</th>
              <th>Status</th>
              <th>Tentativa</th>
              <th>Atualização</th>
              <th>Erro</th>
            </tr>
          </thead>
          <tbody id="rv-lote-detail-body">
            <tr><td colspan="5" class="lote-empty-inline">Selecione um lote para ver os detalhes.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="rv-table-wrap" id="rv-lotes-wrap"><div class="rv-empty">Carregando lotes...</div></div>
</div>

<!-- ═══ SEGMENTOS ═══ -->
<div id="tab-segmentos" class="rv-panel">
  <div style="margin-bottom:1rem">
    <h2 style="font-size:1rem;font-weight:700;color:#0f172a">Segmentos de Reativação</h2>
    <p style="font-size:.78rem;color:#64748b;margin-top:.15rem">Gerencie clientes por estágio no funil</p>
  </div>
  <div class="seg-btns">
    <button class="seg-btn active" onclick="loadSeg('respondeu_1',this)">✅ Responderam (1ª)</button>
    <button class="seg-btn" onclick="loadSeg('respondeu_2',this)">✅ Responderam (2ª)</button>
    <button class="seg-btn" onclick="loadSeg('aguardando_2',this)">⏳ Aguardando 2ª</button>
    <button class="seg-btn" onclick="loadSeg('sem_resposta',this)">⚠️ Sem resposta</button>
    <button class="seg-btn" onclick="loadSeg('standby',this)">🔕 Standby</button>
    <button class="seg-btn" onclick="loadSeg('numero_invalido',this)">❌ Nº inválido</button>
  </div>
  <div id="seg-sel-bar" class="rv-sel-bar" style="display:none">
    <span><strong id="seg-sel-count">0</strong> selecionados</span>
    <div style="display:flex;align-items:center;gap:.5rem">
      <span style="font-size:.75rem;color:#64748b">Mover para:</span>
      <select class="rv-select" id="seg-move-to">
        <option value="elegivel">Elegível (reiniciar)</option>
        <option value="aguardando_2">Aguardando 2ª</option>
        <option value="standby">Standby</option>
        <option value="numero_invalido">Nº inválido</option>
      </select>
      <button class="btn btn-primary btn-sm" onclick="moveSegSelected()">Mover</button>
      <button class="btn btn-ghost btn-sm" onclick="segSelectAll(false)">Limpar</button>
    </div>
  </div>
  <div class="rv-table-wrap" id="seg-wrap"><div class="rv-empty">Selecione um segmento acima</div></div>
</div>


<!-- ═══ PÓS-BARBEARIA ═══ -->
<div id="tab-pos_barbearia" class="rv-panel">

  <div style="margin-bottom:1.25rem">
    <h2 style="font-size:1rem;font-weight:700;color:#0f172a">✂️ Lembrete Pós-Barbearia</h2>
    <p style="font-size:.78rem;color:#64748b;margin-top:.15rem">
      Clientes que <strong>vieram recentemente</strong> — envie lembretes de agenda, promoções e lançamentos antes que esfriem.
    </p>
  </div>

  <!-- Banner explicativo -->
  <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:.85rem 1.25rem;font-size:.82rem;color:#166534;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
    <span style="font-size:1.2rem;flex-shrink:0;">💡</span>
    <div>
      <strong>Para que serve esta aba?</strong><br>
      Diferente da "Reativação" (clientes sumidos), aqui você fala com quem <em>acabou de ir</em> na barbearia — mantendo o relacionamento quente, divulgando promoções e facilitando o re-agendamento.
    </div>
  </div>

  <!-- Filtros -->
  <div class="rv-filters">
    <div class="rv-fg">
      <label>Período do último agendamento</label>
      <select class="rv-select" id="pb-dias">
        <option value="20">Últimos 20 dias</option>
        <option value="30" selected>Últimos 30 dias</option>
        <option value="45">Últimos 45 dias</option>
        <option value="60">Últimos 60 dias</option>
      </select>
    </div>
    <div class="rv-fg">
      <label>Variação de mensagem</label>
      <select class="rv-select" id="pb-variacao">
        <option value="0">Variação 1 — Lembrete de agenda</option>
        <option value="1">Variação 2 — Promoção / lançamento</option>
        <option value="2">Variação 3 — Link da agenda online</option>
        <option value="3">Variação 4 — Feedback + retorno</option>
        <option value="4">Variação 5 — Oferta exclusiva</option>
      </select>
    </div>
    <div class="rv-fg">
      <label>Limite</label>
      <select class="rv-select" id="pb-limite">
        <option value="15">15 clientes</option>
        <option value="20" selected>20 clientes</option>
        <option value="30">30 clientes</option>
        <option value="50">50 clientes</option>
      </select>
    </div>
    <div class="rv-fg" style="justify-content:flex-end;padding-top:18px">
      <button class="btn btn-primary" onclick="pbLoad()">🔍 Buscar</button>
    </div>
  </div>

  <!-- Preview das mensagens -->
  <div class="rv-card" style="margin-bottom:1.25rem">
    <div class="rv-card-hd">
      <h3>📝 Prévia das mensagens disponíveis</h3>
      <span style="font-size:.73rem;color:#94a3b8">Use {nome} para o primeiro nome · {link_agenda} para o link da agenda online</span>
    </div>
    <div style="padding:.85rem 1.25rem;display:grid;grid-template-columns:1fr 1fr;gap:.75rem" id="pb-msg-previews">
      <!-- Preenchido pelo JS -->
    </div>
  </div>

  <!-- Barra de seleção -->
  <div id="pb-sel-bar" class="rv-sel-bar" style="display:none">
    <span><strong id="pb-sel-count">0</strong> clientes selecionados</span>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-ghost btn-sm" onclick="pbSelectAll(true)">Todos</button>
      <button class="btn btn-ghost btn-sm" onclick="pbSelectAll(false)">Limpar</button>
      <button class="btn btn-primary btn-sm" onclick="pbOpenModal()">Criar lote →</button>
    </div>
  </div>

  <!-- Tabela de clientes -->
  <div class="rv-table-wrap">
    <div id="pb-loading" class="rv-empty">Clique em <strong>Buscar</strong> para carregar clientes recentes da barbearia.</div>
    <table class="rv-table" id="pb-table" style="display:none">
      <thead><tr>
        <th style="width:36px"><input type="checkbox" id="pb-check-all" onchange="pbToggleAll(this)"></th>
        <th>Cliente</th>
        <th>WhatsApp</th>
        <th>Último agendamento</th>
        <th>Dias atrás</th>
        <th>Prévia da mensagem</th>
      </tr></thead>
      <tbody id="pb-tbody"></tbody>
    </table>
  </div>

</div>

<!-- MODAL PÓS-BARBEARIA -->
<div class="rv-modal-ov" id="pb-modal" onclick="if(event.target===this)pbCloseModal()">
  <div class="rv-modal">
    <h2>✂️ Confirmar lote pós-barbearia</h2>
    <div id="pb-modal-summary" style="font-size:.82rem;color:#64748b;margin-bottom:1rem"></div>
    <p style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.4rem">Prévia da mensagem</p>
    <div class="rv-msg-preview" id="pb-modal-preview" style="border-left-color:#22c55e"></div>
    <p style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.4rem;margin-top:1rem">Configuração</p>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem">
      <div class="rv-cfg-row"><span class="rv-cfg-lbl">Clientes</span><span class="rv-cfg-val" id="pb-cfg-total">—</span></div>
      <div class="rv-cfg-row"><span class="rv-cfg-lbl">Tipo</span><span class="rv-cfg-val">Pós-barbearia (cliente recente)</span></div>
      <div class="rv-cfg-row"><span class="rv-cfg-lbl">Intervalo</span><span class="rv-cfg-val">180–320s randomizado</span></div>
      <div class="rv-cfg-row" style="border:none"><span class="rv-cfg-lbl">Horário permitido</span><span class="rv-cfg-val">09:00 – 20:00</span></div>
    </div>
    <p style="font-size:.78rem;color:#64748b;margin-bottom:.3rem">Observação (opcional)</p>
    <textarea class="rv-obs" id="pb-modal-obs" rows="2" placeholder="Ex: Campanha abril, promoção coleção inverno..."></textarea>
    <div style="display:flex;gap:.6rem;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="pbCloseModal()">Cancelar</button>
      <button class="btn btn-primary" id="pb-btn-confirm" onclick="pbConfirmLote()">Criar lote</button>
    </div>
  </div>
</div>

<!-- ═══ MENSAGENS ═══ -->
<div id="tab-mensagens" class="rv-panel">

  <!-- Evolution API Config Card -->
  <div id="evolution-config-card" style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:1.25rem 1.5rem;margin-bottom:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;gap:1rem" onclick="toggleEvolutionCard()">
      <div style="display:flex;align-items:center;gap:.6rem">
        <span style="font-size:1.15rem">⚡</span>
        <div>
          <h3 style="font-size:.9rem;font-weight:700;color:#0f172a">Configuração da Evolution API</h3>
          <p id="evo-status-line" style="font-size:.72rem;color:#94a3b8;margin-top:.1rem">Carregando...</p>
        </div>
      </div>
      <span id="evo-card-arrow" style="font-size:.8rem;color:#94a3b8;transition:transform .2s">▼</span>
    </div>
    <div id="evo-config-body" style="display:none;margin-top:1rem;border-top:1px solid #f1f5f9;padding-top:1rem">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.85rem;margin-bottom:1rem">
        <div>
          <label style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;display:block;margin-bottom:.3rem">URL da API</label>
          <input id="evo-api-url" type="text" placeholder="https://api.evolution.io" 
            style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;outline:none;font-family:inherit;background:#f8fafc;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;display:block;margin-bottom:.3rem">API Key</label>
          <input id="evo-api-key" type="password" placeholder="••••••••••••••••"
            style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;outline:none;font-family:inherit;background:#f8fafc;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;display:block;margin-bottom:.3rem">Instância</label>
          <input id="evo-instance" type="text" placeholder="loja_oficial"
            style="width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;outline:none;font-family:inherit;background:#f8fafc;box-sizing:border-box">
        </div>
      </div>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
        <button class="btn btn-primary" onclick="saveEvolutionConfig()" id="btn-save-evo">💾 Salvar configuração</button>
        <button class="btn" onclick="testEvolutionConnection()" id="btn-test-evo" style="background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0">🔌 Testar conexão</button>
        <span id="evo-feedback" style="font-size:.78rem;color:#64748b"></span>
      </div>
    </div>
  </div>

  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem">
    <div>
      <h2 style="font-size:1rem;font-weight:700;color:#0f172a">Mensagens de Reativação</h2>
      <p style="font-size:.78rem;color:#64748b;margin-top:.15rem">Edite os textos, veja a prévia e envie um teste. Use <code style="background:#f1f5f9;padding:.1rem .3rem;border-radius:3px">{nome}</code> para o primeiro nome do cliente.</p>
    </div>
    <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b">Preview com nome</label>
        <input id="msg-preview-nome" type="text" value="Carlos" placeholder="Ex: Carlos"
          oninput="refreshAllPreviews()"
          style="padding:.45rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;width:140px;outline:none;background:#f8fafc;font-family:inherit">
      </div>
      <div style="display:flex;flex-direction:column;gap:.2rem">
        <label style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b">WhatsApp p/ teste</label>
        <input id="msg-test-wa" type="text" placeholder="5565999999999"
          style="padding:.45rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;width:160px;outline:none;background:#f8fafc;font-family:inherit">
      </div>
      <div style="padding-top:18px">
        <button class="btn btn-primary" onclick="saveAllMessages()" id="btn-save-msgs">💾 Salvar tudo</button>
      </div>
    </div>
  </div>

  <!-- Filtro de contexto -->
  <div class="seg-btns" id="msg-ctx-filter" style="margin-bottom:1.25rem">
    <button class="seg-btn active" onclick="filterMsgCtx('todos',this)">Todos</button>
    <button class="seg-btn" onclick="filterMsgCtx('pdv',this)">🛒 PDV</button>
    <button class="seg-btn" onclick="filterMsgCtx('barbearia',this)">✂️ Barbearia</button>
    <button class="seg-btn" onclick="filterMsgCtx('whatsapp',this)">💬 WhatsApp</button>
  </div>

  <div id="msg-list">
    <div class="rv-empty">Carregando mensagens...</div>
  </div>
</div>

<!-- MODAL -->
<div class="rv-modal-ov" id="rv-modal" onclick="if(event.target===this)closeModal()">
  <div class="rv-modal">
    <h2>Confirmar criação do lote</h2>
    <div id="modal-summary" style="font-size:.82rem;color:#64748b;margin-bottom:1rem"></div>
    <p style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.4rem">Prévia da mensagem</p>
    <div class="rv-msg-preview" id="modal-preview"></div>
    <p style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.4rem">Configuração</p>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem">
      <div class="rv-cfg-row"><span class="rv-cfg-lbl">Clientes</span><span class="rv-cfg-val" id="cfg-total">—</span></div>
      <div class="rv-cfg-row"><span class="rv-cfg-lbl">Tentativa</span><span class="rv-cfg-val" id="cfg-tent">—</span></div>
      <div class="rv-cfg-row"><span class="rv-cfg-lbl">Intervalo</span><span class="rv-cfg-val">45–180s randomizado</span></div>
      <div class="rv-cfg-row" style="border:none"><span class="rv-cfg-lbl">Horário permitido</span><span class="rv-cfg-val">09:00 – 20:00</span></div>
    </div>
    <p style="font-size:.78rem;color:#64748b;margin-bottom:.3rem">Observação (opcional)</p>
    <textarea class="rv-obs" id="modal-obs" rows="2" placeholder="Ex: Campanha março, clientes barbearia..."></textarea>
    <div style="display:flex;gap:.6rem;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="closeModal()">Cancelar</button>
      <button class="btn btn-primary" id="btn-confirm" onclick="confirmLote()">Criar lote</button>
    </div>
  </div>
</div>

<script>
const API='reativacao_api.php';
let ST={eligible:[],selected:new Set(),activeLoteId:<?= $stats['lote_ativo']?(int)$stats['lote_ativo']['id']:'null' ?>,sending:false,timer:null,seg:'respondeu_1',segSel:new Set()};

document.querySelectorAll('.rv-tab').forEach(b=>b.addEventListener('click',()=>switchTab(b.dataset.tab)));
function switchTab(tab){
  document.querySelectorAll('.rv-tab').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));
  document.querySelectorAll('.rv-panel').forEach(p=>p.classList.toggle('active',p.id==='tab-'+tab));
  if(tab==='lotes')loadLotes();
  if(tab==='segmentos')loadSeg(ST.seg);
  if(tab==='mensagens')loadMessages();
}

async function loadEligible(){
  const dias=document.getElementById('f-dias').value,ctx=document.getElementById('f-contexto').value,tent=document.getElementById('f-tentativa').value,limite=document.getElementById('f-limite').value;
  const loading=document.getElementById('rv-eligible-loading');
  loading.innerHTML='<div style="padding:2rem;text-align:center;color:#94a3b8">🔍 Buscando...</div>';
  loading.style.display='block';
  document.getElementById('rv-eligible-table').style.display='none';
  try{
    const r=await fetch(`${API}?action=get_eligible&dias=${dias}&contexto=${ctx}&tentativa=${tent}&limite=${limite}`);
    const d=await r.json();
    if(!d.ok){loading.innerHTML=`<div class="rv-empty">❌ Erro: ${esc(d.error||'Falha')}</div>`;return;}
    ST.eligible=d.clients||[];
    ST.selected=new Set(ST.eligible.map(c=>parseInt(c.id)));
    renderEligible();
  }catch(e){loading.innerHTML='<div class="rv-empty">❌ Erro de comunicação.</div>';}
}
function renderEligible(){
  const loading=document.getElementById('rv-eligible-loading'),table=document.getElementById('rv-eligible-table'),tbody=document.getElementById('rv-eligible-tbody');
  if(!ST.eligible.length){loading.innerHTML='<div class="rv-empty">Nenhum cliente elegível com estes filtros.</div>';loading.style.display='block';table.style.display='none';return;}
  loading.style.display='none';table.style.display='table';
  tbody.innerHTML=ST.eligible.map(c=>{
    const ctx=c.contexto_detectado||'whatsapp',dias=c.dias_ausente>=999?'nunca':c.dias_ausente+'d',diasC=c.dias_ausente>=180?'dias-hot':c.dias_ausente>=90?'dias-warm':'dias-cold';
    const wa=(c.whatsapp||'').slice(0,4)+'****'+(c.whatsapp||'').slice(-4),ctxN={pdv:'PDV',barbearia:'Barbearia',whatsapp:'WhatsApp'}[ctx]||ctx;
    const prev=(c.msg_preview||'').replace(/\n/g,' '),chk=ST.selected.has(parseInt(c.id))?'checked':'';
    return `<tr><td><input type="checkbox" class="row-ck" data-id="${c.id}" ${chk} onchange="toggleRow(${c.id},this)"></td><td style="font-weight:600;color:#0f172a">${esc(c.nome)}</td><td style="font-family:monospace;font-size:.78rem;color:#64748b">${wa}</td><td><span class="ctx-badge ctx-${ctx}">${ctxN}</span></td><td><span class="dias-badge ${diasC}">${dias}</span></td><td style="font-size:.75rem;color:#64748b;font-style:italic;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(c.msg_preview)}">${esc(prev)}</td></tr>`;
  }).join('');
  updateSelBar();
}
function toggleRow(id,cb){if(cb.checked)ST.selected.add(parseInt(id));else ST.selected.delete(parseInt(id));updateSelBar();}
function toggleAll(cb){ST.eligible.forEach(c=>{if(cb.checked)ST.selected.add(parseInt(c.id));else ST.selected.delete(parseInt(c.id));});document.querySelectorAll('.row-ck').forEach(c=>c.checked=cb.checked);updateSelBar();}
function selectAll(v){document.getElementById('check-all').checked=v;toggleAll({checked:v});}
function updateSelBar(){document.getElementById('rv-sel-count').textContent=ST.selected.size;document.getElementById('rv-sel-bar').style.display=ST.selected.size>0?'flex':'none';}

function openModal(){
  if(!ST.selected.size)return;
  const tent=parseInt(document.getElementById('f-tentativa').value),first=ST.eligible.find(c=>ST.selected.has(parseInt(c.id)));
  document.getElementById('cfg-total').textContent=ST.selected.size+' clientes';
  document.getElementById('cfg-tent').textContent=tent+'ª mensagem';
  document.getElementById('modal-preview').textContent=first?first.msg_preview:'';
  document.getElementById('modal-summary').textContent=`Lote com ${ST.selected.size} clientes para a ${tent}ª mensagem de reativação.`;
  document.getElementById('rv-modal').classList.add('open');
}
function closeModal(){document.getElementById('rv-modal').classList.remove('open');}
async function confirmLote(){
  const btn=document.getElementById('btn-confirm');btn.disabled=true;btn.textContent='Criando...';
  const tent=parseInt(document.getElementById('f-tentativa').value),obs=document.getElementById('modal-obs').value,ctx=document.getElementById('f-contexto').value;
  try{
    const r=await fetch(`${API}?action=create_lote`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({client_ids:[...ST.selected],tentativa:tent,observacoes:obs,contexto:ctx})});
    const d=await r.json();closeModal();
    if(d.ok){ST.activeLoteId=d.lote_id;alert(`✅ Lote criado com ${d.total} clientes!\n\nVá para Histórico para iniciar o envio.`);switchTab('lotes');}
    else alert('Erro: '+(d.error||'Falha'));
  }catch(e){alert('Erro de comunicação.');}
  btn.disabled=false;btn.textContent='Criar lote';
}

function fmtLocalDateTime(v){
  if(!v)return '—';
  return new Date(v.replace(' ','T')+'Z').toLocaleString('pt-BR',{timeZone:'America/Cuiaba',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
}
function maskPhone(v){
  const s=String(v||'');
  return s ? s.slice(0,4)+'****'+s.slice(-4) : '—';
}
function getEnvioStatusMeta(status){
  return {
    enviado:{label:'Enviado', cls:'env-enviado', icon:'✓'},
    erro:{label:'Erro', cls:'env-erro', icon:'✗'},
    respondeu:{label:'Respondeu', cls:'env-respondeu', icon:'↩'},
    pendente:{label:'Pendente', cls:'env-pendente', icon:'○'}
  }[status] || {label:status||'—', cls:'env-pendente', icon:'○'};
}
function closeLoteDetail(){
  const card=document.getElementById('rv-lote-detail-card');
  if(card) card.style.display='none';
}
function syncReactivationPolicyUI(){
  const historyDelayBadge = document.querySelector('#rv-send-panel label + span');
  if(historyDelayBadge) historyDelayBadge.textContent = '180-320s aleatorio';
  document.querySelectorAll('.rv-cfg-row').forEach(row => {
    const lbl = row.querySelector('.rv-cfg-lbl');
    const val = row.querySelector('.rv-cfg-val');
    if(lbl && val && lbl.textContent.trim() === 'Intervalo') val.textContent = '180-320s randomizado';
  });
}
async function loadLotes(){
  const d=await fetch(`${API}?action=get_lotes`).then(r=>r.json()).catch(()=>({ok:false,lotes:[]}));
  const wrap=document.getElementById('rv-lotes-wrap'),sp=document.getElementById('rv-send-panel');
  const la=(d.lotes||[]).find(l=>l.status==='aguardando'||l.status==='em_andamento');
  if(la){
    ST.activeLoteId=la.id;sp.style.display='block';
    // Seed progress bar with existing data
    ST_total=la.total_clientes||0; ST_ok=la.enviados||0; ST_err=la.erros||0;
    updateProgress(ST_ok, ST_total, ST_err);
  }else sp.style.display='none';
  if(!d.lotes?.length){wrap.innerHTML='<div class="rv-empty">Nenhum lote ainda. Crie um na aba "Criar Lote".</div>';return;}
  wrap.innerHTML=d.lotes.map(l=>{
    const total=parseInt(l.total_clientes||0,10), enviados=parseInt(l.enviados||0,10), erros=parseInt(l.erros||0,10), respostas=parseInt(l.responderam||0,10);
    const processados=enviados+erros;
    const pct=total>0?Math.round(processados/total*100):0;
    const dt=fmtLocalDateTime(l.criado_em);
    const stC={aguardando:'st-aguardando',em_andamento:'st-andamento',concluido:'st-concluido',cancelado:'st-cancelado'}[l.status]||'st-cancelado';
    return `<div class="lote-row"><span style="font-size:.7rem;font-family:monospace;color:#94a3b8">#${l.id}</span><div><div style="font-weight:600;color:#0f172a">${esc(l.observacoes||'Lote #'+l.id)}</div><div style="font-size:.72rem;color:#94a3b8;margin-top:2px">${dt} · ${total} clientes · ${enviados} enviados · ${erros} erros · ${respostas} respostas</div></div><div style="font-size:.75rem;font-family:monospace">${pct}%</div><div style="width:60px;height:4px;background:#f1f5f9;border-radius:2px;overflow:hidden"><div style="height:100%;background:#6366f1;width:${pct}%"></div></div><span class="st-badge ${stC}">${l.status}</span><div style="text-align:right"><button class="btn btn-ghost btn-sm" onclick="loadLoteDetail(${l.id})">Detalhes</button></div></div>`;
  }).join('');
}
async function loadLoteDetail(id){
  const d=await fetch(`${API}?action=get_lote&id=${id}`).then(r=>r.json()).catch(()=>({ok:false}));
  if(!d.ok)return;
  const card=document.getElementById('rv-lote-detail-card');
  const title=document.getElementById('rv-lote-detail-title');
  const sub=document.getElementById('rv-lote-detail-sub');
  const stats=document.getElementById('rv-lote-detail-stats');
  const body=document.getElementById('rv-lote-detail-body');
  const envios=d.envios||[];
  const total=parseInt(d.lote?.total_clientes||envios.length||0,10);
  const enviados=envios.filter(e=>e.status==='enviado'||e.status==='respondeu').length;
  const erros=envios.filter(e=>e.status==='erro').length;
  const respostas=envios.filter(e=>e.status==='respondeu').length;
  const processados=enviados+erros;
  title.textContent=`Lote #${d.lote?.id||id}`;
  sub.textContent=`${fmtLocalDateTime(d.lote?.criado_em)} · ${d.lote?.status||'—'} · ${processados}/${total} processados`;
  stats.innerHTML=[
    {lbl:'Processados',val:`${processados}/${total}`,sub:'enviados + erros'},
    {lbl:'Enviados',val:String(enviados),sub:'aceitos pela API'},
    {lbl:'Erros',val:String(erros),sub:'falhas de envio'},
    {lbl:'Respostas',val:String(respostas),sub:'cliente interagiu'}
  ].map(s=>`<div class="lote-stat"><div class="lote-stat-lbl">${s.lbl}</div><div class="lote-stat-val">${s.val}</div><div class="lote-stat-sub">${s.sub}</div></div>`).join('');
  body.innerHTML=envios.length?envios.map(e=>{
    const meta=getEnvioStatusMeta(e.status);
    const tentativa=parseInt(e.tentativa||0,10);
    const times=[
      e.enviado_em?`Enviado: ${fmtLocalDateTime(e.enviado_em)}`:'Envio: —',
      e.respondeu_em?`Resposta: ${fmtLocalDateTime(e.respondeu_em)}`:'Resposta: —'
    ].join('<br>');
    return `<tr><td><div class="lote-client-cell"><span class="lote-client-name">${esc(e.nome)}</span><span class="lote-client-sub">${maskPhone(e.whatsapp)}</span><span class="lote-client-msg">${esc(e.msg_preview||'')}</span></div></td><td><span class="env-badge ${meta.cls}">${meta.icon} ${meta.label}</span></td><td style="font-family:monospace">${tentativa||'—'}ª</td><td><div class="lote-time-cell">${times}</div></td><td style="font-size:.75rem;color:${e.erro_msg?'#b91c1c':'#94a3b8'}">${esc(e.erro_msg||'—')}</td></tr>`;
  }).join(''):`<tr><td colspan="5" class="lote-empty-inline">Nenhum envio registrado neste lote.</td></tr>`;
  card.style.display='block';
  card.scrollIntoView({behavior:'smooth',block:'start'});
  return;
}

/* ── Progresso ── */
let ST_ok=0, ST_err=0, ST_total=0, ST_countdown=null;

function setStatus(text){ const el=document.getElementById('console-status'); if(el) el.textContent=text; }
function setDot(on){ const el=document.getElementById('dot-pulse'); if(el) el.className='dot-pulse '+(on?'on':'off'); }

function updateProgress(enviados, total, erros){
  ST_total = total || ST_total;
  ST_ok    = enviados;
  ST_err   = erros || 0;
  const processed = Math.min(ST_total, ST_ok + ST_err);
  const pct = ST_total > 0 ? Math.round(processed / ST_total * 100) : 0;
  const bar = document.getElementById('prog-bar');
  const frac = document.getElementById('prog-fraction');
  const pctEl = document.getElementById('prog-pct');
  const okEl  = document.getElementById('prog-ok');
  const errEl = document.getElementById('prog-err');
  if(bar)  bar.style.width  = pct + '%';
  if(frac) frac.textContent = `${processed} / ${ST_total}`;
  if(pctEl) pctEl.textContent = pct + '%';
  if(okEl)  okEl.textContent  = `✓ ${ST_ok} enviado${ST_ok!==1?'s':''}`;
  if(errEl) errEl.textContent = `✗ ${ST_err} erro${ST_err!==1?'s':''}`;
  errEl.style.display = ST_err > 0 ? '' : 'none';
  document.getElementById('rv-progress-wrap').style.display = 'block';
}

function startCountdown(seconds){
  stopCountdown();
  let rem = Math.round(seconds);
  const el = document.getElementById('prog-next');
  const wrap = document.getElementById('prog-next-wrap');
  if(wrap) wrap.style.display = '';
  const tick = () => {
    if(!ST.sending){ if(el) el.textContent='—'; return; }
    if(el) el.textContent = rem + 's';
    if(rem <= 0) return;
    rem--;
    ST_countdown = setTimeout(tick, 1000);
  };
  tick();
}

function stopCountdown(){
  if(ST_countdown){ clearTimeout(ST_countdown); ST_countdown=null; }
  const wrap = document.getElementById('prog-next-wrap');
  if(wrap) wrap.style.display = 'none';
}

function startSending(){
  if(!ST.activeLoteId)return alert('Nenhum lote ativo.');
  const h=new Date().getHours();if(h<9||h>=20)return alert('⚠️ Envio permitido apenas entre 09:00 e 20:00.');
  ST.sending=true;
  document.getElementById('btn-start').style.display='none';
  document.getElementById('btn-stop').style.display='inline-flex';
  setDot(true); setStatus('Enviando...');
  document.getElementById('rv-progress-wrap').style.display='block';
  addLog('info','▶','Envio iniciado'); scheduleNext();
}

function stopSending(){
  ST.sending=false; clearTimeout(ST.timer); stopCountdown();
  document.getElementById('btn-start').style.display='inline-flex';
  document.getElementById('btn-stop').style.display='none';
  setDot(false); setStatus('Pausado');
  addLog('info','⏸','Pausado pelo usuário');
}

function scheduleNext(){
  if(!ST.sending)return;
  const delay=Math.floor(Math.random()*(455-180+1))+180; // 180–455s aleatório
  ST.timer=setTimeout(sendOne, delay*1000);
  startCountdown(delay);
}

function scheduleNext(){
  if(!ST.sending)return;
  const delay=Math.floor(Math.random()*(320-180+1))+180;
  ST.timer=setTimeout(sendOne, delay*1000);
  startCountdown(delay);
}

async function sendOne(){
  if(!ST.sending)return;
  stopCountdown();
  const h=new Date().getHours();if(h<9||h>=20){stopSending();addLog('info','⏰','Fora do horário. Pausado.');return;}
  setStatus('Enviando mensagem...');
  const fd=new FormData();fd.append('lote_id',ST.activeLoteId);
  const d=await fetch(`${API}?action=send_next`,{method:'POST',body:fd}).then(r=>r.json()).catch(()=>({ok:false,error:'Erro de rede'}));
  if(!d.ok){
    if(d.fatal){stopSending();addLog('err','x',d.error||'Erro fatal no envio');return;}
    if(d.paused){stopSending();addLog('info','⏰',d.error||'Pausado');return;}
    ST_err++; addLog('err','✗',d.error||'Erro desconhecido');
    updateProgress(ST_ok, ST_total, ST_err);
    setStatus('Enviando...'); scheduleNext(); return;
  }
  if(d.done){
    ST.sending=false; stopCountdown();
    setDot(false); setStatus('Concluído ✅');
    document.getElementById('btn-start').style.display='inline-flex';
    document.getElementById('btn-stop').style.display='none';
    updateProgress(ST_ok, ST_total, ST_err);
    const wrap = document.getElementById('prog-next-wrap');
    if(wrap) wrap.style.display='none';
    addLog('ok','✓',`Lote concluído! ${ST_total} clientes processados.`);
    setTimeout(()=>{ document.getElementById('rv-send-panel').style.display='none'; loadLotes(); }, 2500);
    return;
  }
  if(d.enviado) ST_ok++; else ST_err++;
  const total = d.restantes != null ? ST_ok + d.restantes : ST_total;
  updateProgress(ST_ok, total, ST_err);
  addLog(d.enviado?'ok':'err', d.enviado?'✓':'✗',
    `${d.nome} — ${(d.msg_preview||'').slice(0,55)}…  (${d.restantes} restante${d.restantes!==1?'s':''})`);
  setStatus('Enviando...');
  scheduleNext();
}

async function cancelLote(){
  if(!confirm('Cancelar o lote em andamento?'))return;
  stopSending();
  const fd=new FormData();fd.append('lote_id',ST.activeLoteId);
  await fetch(`${API}?action=cancel_lote`,{method:'POST',body:fd});
  ST.activeLoteId=null;
  document.getElementById('rv-send-panel').style.display='none';
  document.getElementById('rv-progress-wrap').style.display='none';
  loadLotes();
}

function addLog(type,ico,text){
  const log=document.getElementById('console-log');
  const t=new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const cls=type==='ok'?'log-ok':type==='err'?'log-err':'log-info';
  const el=document.createElement('div');
  el.className='log-line';
  el.innerHTML=`<span class="log-time">${t}</span><span class="${cls}" style="margin:0 .3rem">${ico}</span><span class="log-text">${esc(text)}</span>`;
  log.appendChild(el); log.scrollTop=log.scrollHeight;
}

async function loadSeg(status,btn){
  ST.seg=status;ST.segSel.clear();document.getElementById('seg-sel-bar').style.display='none';
  if(btn){document.querySelectorAll('.seg-btn').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}
  document.getElementById('seg-wrap').innerHTML='<div class="rv-empty">Carregando...</div>';
  const d=await fetch(`${API}?action=get_clients_by_status&status=${status}`).then(r=>r.json()).catch(()=>({ok:false,clients:[]}));
  if(!d.clients?.length){document.getElementById('seg-wrap').innerHTML='<div class="rv-empty">✨ Nenhum cliente neste segmento.</div>';return;}
  document.getElementById('seg-wrap').innerHTML=`<table class="rv-table"><thead><tr><th style="width:36px"><input type="checkbox" onchange="segAll(this)"></th><th>Nome</th><th>WhatsApp</th><th>Tentativas</th><th>Último envio</th><th>Sem visita</th></tr></thead><tbody>${d.clients.map(c=>{const envio=c.reativ_ultimo_envio?new Date(c.reativ_ultimo_envio.replace(' ','T')+'Z').toLocaleDateString('pt-BR',{timeZone:'America/Cuiaba'}):'—';const dias=c.ultimo_atendimento_em?Math.floor((Date.now()-new Date(c.ultimo_atendimento_em.replace(' ','T')+'Z'))/86400000):999;const diasC=dias>=180?'dias-hot':dias>=90?'dias-warm':'dias-cold';const wa=(c.whatsapp||'').slice(0,4)+'****'+(c.whatsapp||'').slice(-4);return `<tr><td><input type="checkbox" class="seg-ck" data-id="${c.id}" onchange="segToggle(${c.id},this)"></td><td style="font-weight:600;color:#0f172a">${esc(c.nome)}</td><td style="font-family:monospace;font-size:.78rem;color:#64748b">${wa}</td><td style="font-family:monospace;font-size:.78rem">${c.reativ_tentativas||0}×</td><td style="font-size:.75rem;color:#64748b">${envio}</td><td><span class="dias-badge ${diasC}">${dias>=999?'nunca':dias+'d'}</span></td></tr>`;}).join('')}</tbody></table>`;
}
function segToggle(id,cb){if(cb.checked)ST.segSel.add(parseInt(id));else ST.segSel.delete(parseInt(id));const bar=document.getElementById('seg-sel-bar');bar.style.display=ST.segSel.size>0?'flex':'none';document.getElementById('seg-sel-count').textContent=ST.segSel.size;}
function segAll(cb){document.querySelectorAll('.seg-ck').forEach(c=>{c.checked=cb.checked;segToggle(parseInt(c.dataset.id),c);})}
function segSelectAll(v){document.querySelectorAll('.seg-ck').forEach(c=>{c.checked=v;segToggle(parseInt(c.dataset.id),c);})}
async function moveSegSelected(){
  if(!ST.segSel.size)return;
  const ns=document.getElementById('seg-move-to').value;
  const d=await fetch(`${API}?action=update_client_status`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({client_ids:[...ST.segSel],status:ns})}).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok)loadSeg(ST.seg);else alert('Erro: '+(d.error||'Falha'));
}



/* ══════════════════════════════════
   EVOLUTION CONFIG
══════════════════════════════════ */
let evoCardOpen = false;

async function loadEvolutionConfig() {
  const d = await fetch(`${API}?action=get_evolution_config`).then(r=>r.json()).catch(()=>({ok:false}));
  const line = document.getElementById('evo-status-line');
  if (d.ok) {
    document.getElementById('evo-api-url').value  = d.api_url  || '';
    document.getElementById('evo-api-key').value  = d.api_key  || '';
    document.getElementById('evo-instance').value = d.instance || '';
    const configured = d.api_url && d.api_key && d.instance;
    if (line) line.innerHTML = configured
      ? '<span style="color:#16a34a;font-weight:600">✅ Configurada — '+d.instance+'</span>'
      : '<span style="color:#f59e0b;font-weight:600">⚠️ Não configurada — clique para preencher</span>';
    if (!configured) openEvolutionCard();
  } else {
    if (line) line.textContent = 'Erro ao carregar configuração';
  }
}

function toggleEvolutionCard() {
  evoCardOpen ? closeEvolutionCard() : openEvolutionCard();
}
function openEvolutionCard() {
  evoCardOpen = true;
  const body = document.getElementById('evo-config-body');
  const arrow = document.getElementById('evo-card-arrow');
  if (body)  body.style.display  = 'block';
  if (arrow) arrow.style.transform = 'rotate(180deg)';
}
function closeEvolutionCard() {
  evoCardOpen = false;
  const body = document.getElementById('evo-config-body');
  const arrow = document.getElementById('evo-card-arrow');
  if (body)  body.style.display  = 'none';
  if (arrow) arrow.style.transform = 'rotate(0deg)';
}

async function saveEvolutionConfig() {
  const btn = document.getElementById('btn-save-evo');
  const fb  = document.getElementById('evo-feedback');
  const payload = {
    api_url:  document.getElementById('evo-api-url').value.trim(),
    api_key:  document.getElementById('evo-api-key').value.trim(),
    instance: document.getElementById('evo-instance').value.trim(),
  };
  btn.disabled = true; btn.textContent = '⏳ Salvando...';
  const d = await fetch(`${API}?action=save_evolution_config`, {
    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
  }).then(r=>r.json()).catch(()=>({ok:false}));
  btn.disabled = false; btn.textContent = '💾 Salvar configuração';
  if (d.ok) {
    if (fb) { fb.style.color='#16a34a'; fb.textContent = '✅ Salvo!'; setTimeout(()=>{fb.textContent=''},3000); }
    await loadEvolutionConfig();
    closeEvolutionCard();
  } else {
    if (fb) { fb.style.color='#dc2626'; fb.textContent = '❌ ' + (d.error||'Falha'); }
  }
}

async function testEvolutionConnection() {
  const btn = document.getElementById('btn-test-evo');
  const fb  = document.getElementById('evo-feedback');
  // Save first then test
  await saveEvolutionConfig();
  btn.disabled = true; btn.textContent = '⏳ Testando...';
  const d = await fetch(`${API}?action=test_evolution_connection`).then(r=>r.json()).catch(()=>({ok:false,error:'Erro de rede'}));
  btn.disabled = false; btn.textContent = '🔌 Testar conexão';
  if (fb) {
    fb.style.color = d.ok ? '#16a34a' : '#dc2626';
    fb.textContent  = d.ok ? (d.message || '✅ Conectado!') : ('❌ ' + (d.error||'Falha'));
    setTimeout(()=>{if(fb)fb.textContent=''},6000);
  }
}

/* ══════════════════════════════════
   MENSAGENS
══════════════════════════════════ */
let MSG_DATA = []; // [{contexto, tentativa, variacao_idx, mensagem, is_custom, default}]
let MSG_DIRTY = {}; // key -> modified text

const CTX_INFO = {
  pdv:       { label:'PDV — Loja Física',     icon:'🛒', desc:'Clientes que compraram na loja física' },
  barbearia: { label:'Barbearia',             icon:'✂️', desc:'Clientes com agendamento na barbearia' },
  whatsapp:  { label:'Só WhatsApp',           icon:'💬', desc:'Clientes que só interagiram pelo chat' },
};

async function loadMessages() {
  await loadEvolutionConfig();
  document.getElementById('msg-list').innerHTML = '<div class="rv-empty">Carregando...</div>';
  const d = await fetch(`${API}?action=get_messages_config`).then(r=>r.json()).catch(()=>({ok:false}));
  if (!d.ok) { document.getElementById('msg-list').innerHTML = '<div class="rv-empty">❌ Erro ao carregar mensagens.</div>'; return; }
  MSG_DATA = d.mensagens || [];
  MSG_DIRTY = {};
  renderMessages('todos');
}

function filterMsgCtx(ctx, btn) {
  document.querySelectorAll('#msg-ctx-filter .seg-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderMessages(ctx);
}

function renderMessages(ctxFilter) {
  const list = document.getElementById('msg-list');
  const nome = document.getElementById('msg-preview-nome').value || 'Carlos';

  // Agrupa por contexto
  const grouped = {};
  MSG_DATA.forEach(m => {
    if (ctxFilter !== 'todos' && m.contexto !== ctxFilter) return;
    if (!grouped[m.contexto]) grouped[m.contexto] = [];
    grouped[m.contexto].push(m);
  });

  if (!Object.keys(grouped).length) { list.innerHTML = '<div class="rv-empty">Nenhuma mensagem neste contexto.</div>'; return; }

  let html = '';
  for (const [ctx, msgs] of Object.entries(grouped)) {
    const info = CTX_INFO[ctx] || { label: ctx, icon:'📩', desc:'' };
    html += `<div class="msg-ctx-section" data-ctx="${ctx}">
      <div class="ctx-section-hd">
        <span class="ctx-section-hd-icon">${info.icon}</span>
        <div><h3>${info.label}</h3><p>${info.desc}</p></div>
      </div>`;

    // Group by tentativa
    const byTent = {};
    msgs.forEach(m => { (byTent[m.tentativa] = byTent[m.tentativa]||[]).push(m); });

    for (const [tent, vars] of Object.entries(byTent)) {
      vars.forEach(m => {
        const key = `${m.contexto}_${m.tentativa}_${m.variacao_idx}`;
        const cur = MSG_DIRTY[key] ?? m.mensagem;
        const preview = cur.replace(/\{nome\}/g, nome);
        const isModified = MSG_DIRTY[key] !== undefined && MSG_DIRTY[key] !== m.default;
        const isCustom   = m.is_custom || isModified;

        html += `<div class="msg-card" id="card-${key}">
          <div class="msg-card-hd">
            <div class="msg-card-meta">
              <span class="msg-tent-badge ${tent==1?'msg-tent-1':'msg-tent-2'}">${tent}ª mensagem</span>
              <span class="msg-var-badge">variação ${parseInt(m.variacao_idx)+1}</span>
              ${isCustom ? '<span class="msg-custom-badge">✏️ customizada</span>' : '<span style="font-size:.65rem;color:#94a3b8">padrão</span>'}
            </div>
            <div class="msg-actions">
              <button class="btn btn-test btn-sm" onclick="sendTest('${key}')" title="Enviar esta mensagem de teste">📤 Testar</button>
              <button class="btn btn-reset btn-sm" onclick="resetMsg('${key}','${ctx}',${tent},${m.variacao_idx})" title="Restaurar texto padrão">↺ Restaurar</button>
            </div>
          </div>
          <div class="msg-card-bd">
            <div class="msg-editor">
              <label>✏️ Editar mensagem</label>
              <textarea class="msg-textarea${isModified?' modified':''}" id="ta-${key}"
                oninput="onMsgInput('${key}',this)"
                rows="5">${esc(cur)}</textarea>
              <p style="font-size:.65rem;color:#94a3b8;margin-top:.15rem">Use <code style="background:#f1f5f9;padding:.05rem .25rem;border-radius:3px">{nome}</code> para o primeiro nome do cliente</p>
            </div>
            <div class="msg-editor">
              <label>👁 Prévia (com "${nome}")</label>
              <div class="msg-preview-box" id="prev-${key}">${esc(preview)}</div>
            </div>
          </div>
        </div>`;
      });
    }
    html += '</div>';
  }

  list.innerHTML = html;
}

function onMsgInput(key, ta) {
  MSG_DIRTY[key] = ta.value;
  // Update preview
  const nome = document.getElementById('msg-preview-nome').value || 'Carlos';
  const prev = document.getElementById('prev-' + key);
  if (prev) prev.textContent = ta.value.replace(/\{nome\}/g, nome);
  // Mark as modified visually
  ta.classList.toggle('modified', true);
  // Update custom badge
  const card = document.getElementById('card-' + key);
  if (card) {
    const meta = card.querySelector('.msg-card-meta');
    const existing = meta.querySelector('.msg-custom-badge, [style*="padrão"]');
    if (existing) existing.outerHTML = '<span class="msg-custom-badge">✏️ customizada</span>';
  }
}

function refreshAllPreviews() {
  const nome = document.getElementById('msg-preview-nome').value || 'Carlos';
  document.querySelectorAll('[id^="ta-"]').forEach(ta => {
    const key = ta.id.replace('ta-','');
    const prev = document.getElementById('prev-' + key);
    if (prev) prev.textContent = ta.value.replace(/\{nome\}/g, nome);
  });
}

function resetMsg(key, ctx, tent, idx) {
  const original = MSG_DATA.find(m => m.contexto===ctx && m.tentativa==tent && m.variacao_idx==idx);
  if (!original) return;
  const ta = document.getElementById('ta-'+key);
  if (!ta) return;
  ta.value = original.default;
  ta.classList.remove('modified');
  delete MSG_DIRTY[key];
  refreshAllPreviews();
  // Update badge
  const card = document.getElementById('card-'+key);
  if (card) {
    const badge = card.querySelector('.msg-custom-badge');
    if (badge) badge.outerHTML = '<span style="font-size:.65rem;color:#94a3b8">padrão</span>';
  }
}

async function sendTest(key) {
  const wa = document.getElementById('msg-test-wa').value.replace(/\D/g,'');
  if (!wa) { alert('Preencha o WhatsApp para teste no topo da página.'); return; }
  const ta = document.getElementById('ta-'+key);
  if (!ta) return;
  const nome = document.getElementById('msg-preview-nome').value || 'Carlos';
  const msg  = ta.value.replace(/\{nome\}/g, nome);

  const btn = document.querySelector(`#card-${key} .btn-test`);
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Enviando...'; }

  const d = await fetch(`${API}?action=send_test`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ whatsapp: wa, mensagem: msg })
  }).then(r=>r.json()).catch(()=>({ok:false,error:'Erro de comunicação'}));

  if (btn) { btn.disabled = false; btn.textContent = '📤 Testar'; }

  if (d.ok) {
    btn.textContent = '✅ Enviado!';
    setTimeout(() => { if(btn) btn.textContent = '📤 Testar'; }, 3000);
  } else {
    alert('Erro ao enviar: ' + (d.error || 'Falha desconhecida'));
  }
}

async function saveAllMessages() {
  const btn = document.getElementById('btn-save-msgs');
  // Coleta todos os textareas visíveis ou do MSG_DATA com dirty
  const toSave = [];
  MSG_DATA.forEach(m => {
    const key = `${m.contexto}_${m.tentativa}_${m.variacao_idx}`;
    const ta  = document.getElementById('ta-'+key);
    const cur = ta ? ta.value : (MSG_DIRTY[key] ?? m.mensagem);
    toSave.push({ contexto: m.contexto, tentativa: m.tentativa, variacao_idx: m.variacao_idx, mensagem: cur });
  });

  btn.disabled = true; btn.textContent = '⏳ Salvando...';
  const d = await fetch(`${API}?action=save_messages_config`, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ mensagens: toSave })
  }).then(r=>r.json()).catch(()=>({ok:false,error:'Erro'}));
  btn.disabled = false; btn.textContent = '💾 Salvar tudo';

  if (d.ok) {
    btn.textContent = `✅ ${d.saved} salvas!`;
    setTimeout(() => { btn.textContent = '💾 Salvar tudo'; }, 3000);
    // Reload to refresh is_custom flags
    await loadMessages();
    // Re-apply current filter
    const activeFilter = document.querySelector('#msg-ctx-filter .seg-btn.active');
    if (activeFilter) {
      const ctx = activeFilter.onclick?.toString().match(/'(\w+)'/)?.[1] || 'todos';
      renderMessages(ctx);
    }
  } else {
    alert('Erro: ' + (d.error || 'Falha'));
  }
}
/* ══════════════════════════════════
   PÓS-BARBEARIA
══════════════════════════════════ */

// Mensagens padrão (editáveis futuramente via banco)
const PB_MSGS = [
  {
    titulo: '📅 Lembrete de agenda',
    texto: 'Oi, {nome}! 👋\n\nJá faz alguns dias desde sua última visita na *Formen Barbearia*. ✂️\n\nSua agenda está aberta — escolha o melhor horário:\n👉 {link_agenda}\n\nTe esperamos!'
  },
  {
    titulo: '🛍️ Promoção / Lançamento',
    texto: 'Oi, {nome}! 😎\n\nTemos novidades esperando por você na *Formen Barbearia*! ✂️\n\nNovos produtos, novos serviços e promoções imperdíveis.\n\nAgende seu horário:\n👉 {link_agenda}\n\nAté breve!'
  },
  {
    titulo: '🔗 Link da agenda online',
    texto: 'Oi, {nome}! Tudo bem? ✂️\n\nQue tal marcar sua próxima visita na *Formen Barbearia*?\n\nAgende online em qualquer horário:\n👉 {link_agenda}\n\nÉ rápido e fácil! 😉'
  },
  {
    titulo: '⭐ Feedback + retorno',
    texto: 'Oi, {nome}! 🙏\n\nEsperamos que tenha gostado do seu atendimento na *Formen Barbearia*! ✂️\n\nSua opinião é muito importante pra gente. E quando quiser voltar, sua agenda está esperando:\n👉 {link_agenda}'
  },
  {
    titulo: '🎁 Oferta exclusiva',
    texto: 'Oi, {nome}! 🎉\n\nTemos uma oferta especial para clientes fiéis como você na *Formen Barbearia*! ✂️\n\nAgende agora e aproveite:\n👉 {link_agenda}\n\nValidade limitada!'
  }
];

const AGENDA_LINK = 'https://crm.formenstore.com.br/agenda.php?empresa=minhaloja';

let PB = { clients: [], selected: new Set() };

// Renderiza previews das mensagens
function pbRenderPreviews() {
  const varidx = parseInt(document.getElementById('pb-variacao').value || '0');
  const wrap   = document.getElementById('pb-msg-previews');
  if (!wrap) return;

  wrap.innerHTML = PB_MSGS.map((m, i) => {
    const preview = m.texto.replace(/\{nome\}/g, 'Carlos').replace(/\{link_agenda\}/g, AGENDA_LINK);
    const isActive = i === varidx;
    return `<div style="border:${isActive?'2px solid #22c55e':'1.5px solid #e2e8f0'};border-radius:10px;padding:.85rem 1rem;background:${isActive?'#f0fdf4':'#f8fafc'};">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
        <span style="font-size:.7rem;font-weight:700;background:${isActive?'#bbf7d0':'#f1f5f9'};color:${isActive?'#15803d':'#64748b'};padding:.15rem .5rem;border-radius:20px;">Variação ${i+1}</span>
        <span style="font-size:.78rem;font-weight:600;color:#0f172a;">${m.titulo}</span>
        ${isActive?'<span style="margin-left:auto;font-size:.65rem;font-weight:700;color:#16a34a;">✓ Selecionada</span>':''}
      </div>
      <div style="font-size:.75rem;color:#475569;white-space:pre-line;line-height:1.55;">${esc(preview)}</div>
    </div>`;
  }).join('');
}

// Atualiza preview quando muda variação
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('pb-variacao');
  if (sel) {
    sel.addEventListener('change', () => {
      pbRenderPreviews();
      pbUpdateMsgPreview();
    });
    pbRenderPreviews();
  }
});

function pbUpdateMsgPreview() {
  const varidx = parseInt(document.getElementById('pb-variacao')?.value || '0');
  const msg    = PB_MSGS[varidx] || PB_MSGS[0];
  return msg.texto.replace(/\{nome\}/g, '{nome}').replace(/\{link_agenda\}/g, AGENDA_LINK);
}

// Carrega clientes recentes da barbearia
async function pbLoad() {
  const dias   = document.getElementById('pb-dias').value;
  const limite = document.getElementById('pb-limite').value;
  const varidx = document.getElementById('pb-variacao').value;

  document.getElementById('pb-loading').innerHTML = '<div style="padding:2rem;text-align:center;color:#94a3b8">🔍 Buscando clientes recentes...</div>';
  document.getElementById('pb-loading').style.display = 'block';
  document.getElementById('pb-table').style.display   = 'none';

  pbRenderPreviews();

  try {
    const r = await fetch(`${API}?action=get_pos_barbearia&dias=${dias}&limite=${limite}&variacao=${varidx}`);
    const d = await r.json();

    if (!d.ok) {
      document.getElementById('pb-loading').innerHTML = `<div class="rv-empty">❌ ${esc(d.error||'Erro ao buscar clientes.')}</div>`;
      return;
    }

    PB.clients  = d.clients || [];
    PB.selected = new Set(PB.clients.map(c => parseInt(c.id)));
    pbRender();

  } catch(e) {
    document.getElementById('pb-loading').innerHTML = '<div class="rv-empty">❌ Erro de comunicação.</div>';
  }
}

function pbRender() {
  const varidx  = parseInt(document.getElementById('pb-variacao').value || '0');
  const msg     = PB_MSGS[varidx] || PB_MSGS[0];
  const loading = document.getElementById('pb-loading');
  const table   = document.getElementById('pb-table');
  const tbody   = document.getElementById('pb-tbody');

  if (!PB.clients.length) {
    loading.innerHTML = '<div class="rv-empty">✨ Nenhum cliente encontrado neste período. Tente ampliar o filtro de dias.</div>';
    loading.style.display = 'block';
    table.style.display   = 'none';
    return;
  }

  loading.style.display = 'none';
  table.style.display   = 'table';

  tbody.innerHTML = PB.clients.map(c => {
    const preview  = msg.texto
      .replace(/\{nome\}/g, c.primeiro_nome || c.nome || 'Cliente')
      .replace(/\{link_agenda\}/g, AGENDA_LINK)
      .replace(/\n/g, ' ').slice(0, 90) + '…';
    const wa = (c.whatsapp || '').slice(0,4) + '****' + (c.whatsapp || '').slice(-4);
    const chk = PB.selected.has(parseInt(c.id)) ? 'checked' : '';
    const diasC = parseInt(c.dias_desde_agendamento) <= 20 ? 'dias-cold' : parseInt(c.dias_desde_agendamento) <= 40 ? 'dias-warm' : 'dias-hot';

    return `<tr>
      <td><input type="checkbox" class="pb-ck" data-id="${c.id}" ${chk} onchange="pbToggleRow(${c.id},this)"></td>
      <td style="font-weight:600;color:#0f172a;">
        ${esc(c.nome)}
        <div style="font-size:.7rem;color:#94a3b8;margin-top:.1rem;">
          ${esc(c.ultimo_servico || '')}
        </div>
      </td>
      <td style="font-family:monospace;font-size:.78rem;color:#64748b;">${wa}</td>
      <td style="font-size:.78rem;color:#475569;">${esc(c.ultimo_agendamento_fmt || '—')}</td>
      <td><span class="dias-badge ${diasC}">${c.dias_desde_agendamento}d</span></td>
      <td style="font-size:.73rem;color:#64748b;font-style:italic;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(msg.texto.replace(/\n/g,' '))}">
        ${esc(preview)}
      </td>
    </tr>`;
  }).join('');

  pbUpdateSelBar();
}

function pbToggleRow(id, cb) {
  if (cb.checked) PB.selected.add(parseInt(id));
  else            PB.selected.delete(parseInt(id));
  pbUpdateSelBar();
}

function pbToggleAll(cb) {
  PB.clients.forEach(c => {
    if (cb.checked) PB.selected.add(parseInt(c.id));
    else            PB.selected.delete(parseInt(c.id));
  });
  document.querySelectorAll('.pb-ck').forEach(c => c.checked = cb.checked);
  pbUpdateSelBar();
}

function pbSelectAll(v) {
  document.getElementById('pb-check-all').checked = v;
  pbToggleAll({ checked: v });
}

function pbUpdateSelBar() {
  document.getElementById('pb-sel-count').textContent    = PB.selected.size;
  document.getElementById('pb-sel-bar').style.display   = PB.selected.size > 0 ? 'flex' : 'none';
}

function pbOpenModal() {
  if (!PB.selected.size) return;
  const varidx   = parseInt(document.getElementById('pb-variacao').value || '0');
  const msg      = PB_MSGS[varidx] || PB_MSGS[0];
  const primeiro = PB.clients.find(c => PB.selected.has(parseInt(c.id)));
  const preview  = msg.texto
    .replace(/\{nome\}/g, primeiro?.primeiro_nome || 'Cliente')
    .replace(/\{link_agenda\}/g, AGENDA_LINK);

  document.getElementById('pb-cfg-total').textContent    = PB.selected.size + ' clientes';
  document.getElementById('pb-modal-preview').textContent = preview;
  document.getElementById('pb-modal-summary').textContent = `Lote de lembrete pós-barbearia com ${PB.selected.size} cliente(s) — "${msg.titulo}"`;
  document.getElementById('pb-modal').classList.add('open');
}

function pbCloseModal() {
  document.getElementById('pb-modal').classList.remove('open');
}

async function pbConfirmLote() {
  const btn    = document.getElementById('pb-btn-confirm');
  const varidx = parseInt(document.getElementById('pb-variacao').value || '0');
  const msg    = PB_MSGS[varidx] || PB_MSGS[0];
  const obs    = document.getElementById('pb-modal-obs').value;

  btn.disabled = true; btn.textContent = 'Criando...';

  // Monta os envios com a mensagem já interpolada por cliente
  const envios = PB.clients
    .filter(c => PB.selected.has(parseInt(c.id)))
    .map(c => ({
      client_id: c.id,
      whatsapp:  c.whatsapp,
      nome:      c.nome,
      mensagem:  msg.texto
        .replace(/\{nome\}/g, c.primeiro_nome || c.nome || 'Cliente')
        .replace(/\{link_agenda\}/g, AGENDA_LINK),
    }));

  try {
    const r = await fetch(`${API}?action=create_lote_pos_barbearia`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ envios, observacoes: obs, variacao_idx: varidx }),
    });
    const d = await r.json();
    pbCloseModal();

    if (d.ok) {
      ST.activeLoteId = d.lote_id;
      alert(`✅ Lote criado com ${d.total} clientes!\n\nVá para Histórico para iniciar o envio.`);
      switchTab('lotes');
    } else {
      alert('Erro: ' + (d.error || 'Falha'));
    }
  } catch(e) {
    alert('Erro de comunicação.');
  }

  btn.disabled = false; btn.textContent = 'Criar lote';
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
syncReactivationPolicyUI();
</script>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
