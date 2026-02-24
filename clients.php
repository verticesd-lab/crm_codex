<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

$pdo = get_pdo();

if (function_exists('pdo_apply_timezone')) {
    pdo_apply_timezone($pdo, '+00:00');
}

$companyId = current_company_id();
$userId    = (int)($_SESSION['user_id'] ?? 0);

if (!$companyId) {
    flash('error', 'Empresa não definida na sessão.');
    redirect('dashboard.php');
}

$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

/* ===========================
   HELPERS LOCAIS
   =========================== */
function phone_digits(?string $phone): string {
    $p = preg_replace('/\D+/', '', (string)$phone);
    return $p ?: '';
}

function clients_url(string $qs = ''): string {
    $path = 'clients.php';
    return $qs ? ($path . '?' . $qs) : $path;
}

function safe_dt(?string $dt): string {
    if (!$dt) return '';
    if (function_exists('format_datetime_br')) {
        try { return format_datetime_br($dt, 'UTC'); } catch (Throwable $e) {}
        try { return format_datetime_br($dt); }        catch (Throwable $e2) {}
    }
    try {
        $utc = new DateTime($dt, new DateTimeZone('UTC'));
        $utc->setTimezone(new DateTimeZone('America/Cuiaba'));
        return $utc->format('d/m/Y H:i');
    } catch (Throwable $e) { return (string)$dt; }
}

function now_utc(): string {
    try { return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }
    catch (Throwable $e) { return gmdate('Y-m-d H:i:s'); }
}

/* ===========================
   CREATE / UPDATE
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'client') {
    $data = [
        'nome'               => trim($_POST['nome'] ?? ''),
        'telefone_principal' => trim($_POST['telefone_principal'] ?? ''),
        'whatsapp'           => trim($_POST['whatsapp'] ?? ''),
        'instagram_username' => trim($_POST['instagram_username'] ?? ''),
        'email'              => trim($_POST['email'] ?? ''),
        'tags'               => trim($_POST['tags'] ?? ''),
        'observacoes'        => trim($_POST['observacoes'] ?? ''),
    ];

    if ($data['nome'] === '') {
        flash('error', 'Informe o nome do cliente.');
        redirect(clients_url('action=' . ($id ? 'edit&id=' . $id : 'create')));
    }

    $data['instagram_username'] = ltrim($data['instagram_username'], '@');
    $nowUtc = now_utc();

    if ($id) {
        $stmt = $pdo->prepare('
            UPDATE clients SET nome=?,telefone_principal=?,whatsapp=?,instagram_username=?,
            email=?,tags=?,observacoes=?,updated_at=? WHERE id=? AND company_id=?
        ');
        $stmt->execute([$data['nome'],$data['telefone_principal'],$data['whatsapp'],
            $data['instagram_username'],$data['email'],$data['tags'],
            $data['observacoes'],$nowUtc,$id,$companyId]);
        log_action($pdo,(int)$companyId,(int)$userId,'cliente_update','Cliente #'.$id);
        flash('success','Cliente atualizado com sucesso.');
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO clients (company_id,nome,telefone_principal,whatsapp,instagram_username,
            email,tags,observacoes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([$companyId,$data['nome'],$data['telefone_principal'],$data['whatsapp'],
            $data['instagram_username'],$data['email'],$data['tags'],
            $data['observacoes'],$nowUtc,$nowUtc]);
        $id = (int)$pdo->lastInsertId();
        log_action($pdo,(int)$companyId,(int)$userId,'cliente_create','Cliente #'.$id);
        flash('success','Cliente criado com sucesso.');
    }
    redirect(clients_url('action=view&id='.(int)$id));
}

/* ===========================
   DELETE
   =========================== */
if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare('DELETE FROM clients WHERE id=? AND company_id=?');
    $stmt->execute([$id,$companyId]);
    log_action($pdo,(int)$companyId,(int)$userId,'cliente_delete','Cliente #'.$id);
    flash('success','Cliente removido.');
    redirect(clients_url());
}

/* ===========================
   IMPORTAÇÃO CSV (AJAX / POST)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'csv_import') {
    header('Content-Type: application/json');
    $file   = $_FILES['csv_file']['tmp_name'] ?? null;
    $handle = $file ? @fopen($file,'r') : false;
    if (!$handle) { echo json_encode(['error'=>'Arquivo inválido']); exit; }

    $header   = fgetcsv($handle);
    $inserted = $updated = $skipped = 0;
    $errors   = [];

    $stmtChk = $pdo->prepare('SELECT id FROM clients WHERE company_id=? AND whatsapp=? LIMIT 1');
    $stmtIns = $pdo->prepare('INSERT INTO clients (company_id,nome,whatsapp,telefone_principal,tags,observacoes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
    $stmtUpd = $pdo->prepare('UPDATE clients SET nome=?,tags=?,updated_at=? WHERE company_id=? AND whatsapp=?');

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 3) { $skipped++; continue; }
        [,$csvNome,$csvWa] = $row;
        $nome  = trim($csvNome);
        $digits = preg_replace('/\D/','',$csvWa);
        if (str_starts_with($digits,'55') && strlen($digits)>=12) $digits=substr($digits,2);
        if (!$nome || strlen($digits)<10 || strlen($digits)>11) { $skipped++; continue; }
        $phone = '55'.$digits;
        try {
            $stmtChk->execute([$companyId,$phone]);
            if ($stmtChk->fetch()) {
                $stmtUpd->execute([$nome,'PDV_reativacao',now_utc(),$companyId,$phone]);
                $updated++;
            } else {
                $stmtIns->execute([$companyId,$nome,$phone,$phone,'PDV_reativacao','Importado via CSV.',
                    gmdate('Y-m-d H:i:s',strtotime('-1 year')),gmdate('Y-m-d H:i:s',strtotime('-1 year'))]);
                $inserted++;
            }
        } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
    fclose($handle);
    echo json_encode(['success'=>true,'inserted'=>$inserted,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors]);
    exit;
}

/* ===========================
   CRIAR ATENDIMENTO
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'interaction' && $id) {
    $canal  = $_POST['canal'] ?? 'whatsapp';
    $titulo = trim($_POST['titulo'] ?? '');
    $resumo = trim($_POST['resumo'] ?? '');
    if ($titulo !== '' && $resumo !== '') {
        $nowUtc = now_utc();
        $pdo->prepare('INSERT INTO interactions (company_id,client_id,canal,origem,titulo,resumo,atendente,created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$companyId,$id,$canal,'manual',$titulo,$resumo,current_user_name(),$nowUtc]);
        $pdo->prepare('UPDATE clients SET ultimo_atendimento_em=?,updated_at=? WHERE id=? AND company_id=?')
            ->execute([$nowUtc,$nowUtc,$id,$companyId]);
        flash('success','Atendimento registrado.');
    } else { flash('error','Preencha título e resumo.'); }
    redirect(clients_url('action=view&id='.(int)$id));
}

include __DIR__ . '/views/partials/header.php';

if ($msg = get_flash('success')) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">'.sanitize($msg).'</div>';
if ($msg = get_flash('error'))   echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">'.sanitize($msg).'</div>';

/* ===========================
   FORM CREATE / EDIT
   =========================== */
if ($action === 'create' || ($action === 'edit' && $id)) {
    $client = ['nome'=>'','telefone_principal'=>'','whatsapp'=>'','instagram_username'=>'','email'=>'','tags'=>'','observacoes'=>''];
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id=? AND company_id=?');
        $stmt->execute([$id,$companyId]);
        $clientDb = $stmt->fetch();
        if ($clientDb) $client = $clientDb;
    }
    ?>
    <style>
      .form-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:2rem; box-shadow:0 1px 3px rgba(0,0,0,.06); max-width:760px; }
      .form-card h1 { font-size:1.35rem; font-weight:700; color:#0f172a; margin-bottom:.25rem; }
      .form-card .subtitle { font-size:.85rem; color:#64748b; margin-bottom:1.75rem; }
      .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
      .form-group label { display:block; font-size:.75rem; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.35rem; }
      .form-group input, .form-group textarea, .form-group select {
        width:100%; padding:.6rem .85rem; border:1.5px solid #e2e8f0; border-radius:8px;
        font-size:.875rem; color:#0f172a; background:#f8fafc; transition:border-color .15s,box-shadow .15s; outline:none;
      }
      .form-group input:focus, .form-group textarea:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); background:#fff; }
      .form-group .hint { font-size:.72rem; color:#94a3b8; margin-top:.3rem; }
      .form-span-2 { grid-column:span 2; }
      .btn-primary { background:#6366f1; color:#fff; padding:.65rem 1.25rem; border:none; border-radius:8px; font-weight:600; font-size:.875rem; cursor:pointer; transition:background .15s; }
      .btn-primary:hover { background:#4f46e5; }
      .btn-ghost { background:#f1f5f9; color:#475569; padding:.65rem 1.25rem; border:none; border-radius:8px; font-weight:600; font-size:.875rem; cursor:pointer; text-decoration:none; display:inline-block; }
    </style>
    <div class="form-card">
        <h1><?= $id ? 'Editar Cliente' : 'Novo Cliente' ?></h1>
        <p class="subtitle"><?= $id ? 'Atualize os dados do cliente' : 'Preencha os dados para cadastrar um novo cliente' ?></p>
        <form method="POST" class="form-grid">
            <input type="hidden" name="form" value="client">
            <div class="form-group">
                <label>Nome *</label>
                <input name="nome" value="<?= sanitize((string)($client['nome']??'')) ?>" placeholder="Ex.: Maria Silva" required>
            </div>
            <div class="form-group">
                <label>Telefone principal</label>
                <input name="telefone_principal" value="<?= sanitize((string)($client['telefone_principal']??'')) ?>" placeholder="5565999999999">
            </div>
            <div class="form-group">
                <label>WhatsApp</label>
                <input name="whatsapp" value="<?= sanitize((string)($client['whatsapp']??'')) ?>" placeholder="5565999999999">
                <p class="hint">Somente números: DDI + DDD + Número</p>
            </div>
            <div class="form-group">
                <label>Instagram</label>
                <input name="instagram_username" value="<?= sanitize((string)($client['instagram_username']??'')) ?>" placeholder="@usuario">
            </div>
            <div class="form-group">
                <label>E-mail</label>
                <input name="email" type="email" value="<?= sanitize((string)($client['email']??'')) ?>" placeholder="contato@email.com">
            </div>
            <div class="form-group">
                <label>Tags</label>
                <input name="tags" value="<?= sanitize((string)($client['tags']??'')) ?>" placeholder="vip, recorrente, pdv">
            </div>
            <div class="form-group form-span-2">
                <label>Observações</label>
                <textarea name="observacoes" rows="3"><?= sanitize((string)($client['observacoes']??'')) ?></textarea>
            </div>
            <div class="form-span-2" style="display:flex;gap:.75rem;padding-top:.5rem;">
                <button type="submit" class="btn-primary"><?= $id ? 'Salvar alterações' : 'Criar cliente' ?></button>
                <a href="<?= sanitize(clients_url()) ?>" class="btn-ghost">Cancelar</a>
            </div>
        </form>
    </div>
    <?php include __DIR__ . '/views/partials/footer.php'; exit;
}

/* ===========================
   VIEW CLIENTE
   =========================== */
if ($action === 'view' && $id) {
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id=? AND company_id=?');
    $stmt->execute([$id,$companyId]);
    $client = $stmt->fetch();
    if (!$client) { echo '<div class="p-3 rounded bg-red-50 text-red-700">Cliente não encontrado.</div>'; include __DIR__.'/views/partials/footer.php'; exit; }

    $ordersStmt = $pdo->prepare('SELECT COUNT(*) as total_pedidos, COALESCE(SUM(total),0) as total_valor FROM orders WHERE company_id=? AND client_id=?');
    $ordersStmt->execute([$companyId,$id]);
    $orderStats = $ordersStmt->fetch() ?: ['total_pedidos'=>0,'total_valor'=>0];

    $interactionsStmt = $pdo->prepare('SELECT * FROM interactions WHERE company_id=? AND client_id=? ORDER BY created_at DESC');
    $interactionsStmt->execute([$companyId,$id]);
    $interactions = $interactionsStmt->fetchAll();

    $clientOpps = [];
    try {
        $oppStmt = $pdo->prepare('SELECT o.*,p.nome as pipeline_nome,s.nome as stage_nome FROM opportunities o JOIN pipelines p ON p.id=o.pipeline_id JOIN pipeline_stages s ON s.id=o.stage_id WHERE o.company_id=? AND o.client_id=? ORDER BY o.created_at DESC');
        $oppStmt->execute([$companyId,$id]); $clientOpps = $oppStmt->fetchAll();
    } catch (Throwable $e) {}

    $waNumber = phone_digits($client['whatsapp'] ?? '');
    $waLink   = $waNumber ? ('https://wa.me/'.$waNumber.'?text='.urlencode('Olá, '.(string)($client['nome']??''))) : '';
    $igUser   = ltrim((string)($client['instagram_username']??''),'@');
    $igLink   = $igUser ? ('https://instagram.com/'.rawurlencode($igUser)) : '';

    // Avatar inicial
    $initials = strtoupper(implode('',array_map(fn($w)=>$w[0],array_slice(explode(' ',trim((string)$client['nome'])),0,2))));
    ?>
    <style>
      .client-header { display:flex; align-items:flex-start; justify-content:space-between; gap:1.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
      .client-avatar { width:56px; height:56px; border-radius:14px; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:1.15rem; flex-shrink:0; }
      .client-meta { flex:1; min-width:0; }
      .client-meta h1 { font-size:1.5rem; font-weight:700; color:#0f172a; }
      .client-meta .email { font-size:.85rem; color:#64748b; margin-top:.15rem; }
      .client-channels { display:flex; gap:.75rem; margin-top:.6rem; flex-wrap:wrap; }
      .channel-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.35rem .8rem; border-radius:20px; font-size:.78rem; font-weight:600; text-decoration:none; transition:opacity .15s; }
      .channel-btn:hover { opacity:.8; }
      .channel-wa { background:#dcfce7; color:#16a34a; }
      .channel-ig { background:#fce7f3; color:#be185d; }
      .client-actions { display:flex; gap:.5rem; flex-shrink:0; }
      .btn-sm { padding:.45rem .9rem; border-radius:8px; font-size:.8rem; font-weight:600; cursor:pointer; text-decoration:none; border:none; display:inline-flex; align-items:center; gap:.3rem; }
      .btn-edit { background:#f1f5f9; color:#334155; }
      .btn-edit:hover { background:#e2e8f0; }
      .btn-delete { background:#fef2f2; color:#dc2626; }
      .btn-delete:hover { background:#fee2e2; }

      .kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
      .kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.1rem 1.25rem; }
      .kpi-label { font-size:.72rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; }
      .kpi-value { font-size:1.5rem; font-weight:700; color:#0f172a; margin-top:.2rem; }
      .kpi-value.indigo { color:#6366f1; }
      .kpi-sub { font-size:.75rem; color:#94a3b8; margin-top:.1rem; }

      .section-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
      .section-card-header { padding:.9rem 1.25rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
      .section-card-header h3 { font-size:.95rem; font-weight:700; color:#0f172a; }
      .section-card-body { padding:1.25rem; }

      .timeline { display:flex; flex-direction:column; gap:0; }
      .timeline-item { display:flex; gap:1rem; padding:.85rem 0; border-bottom:1px solid #f8fafc; }
      .timeline-item:last-child { border-bottom:none; }
      .tl-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.85rem; }
      .tl-icon.manual { background:#eff6ff; }
      .tl-icon.ia { background:#f0fdf4; }
      .tl-content { flex:1; min-width:0; }
      .tl-title { font-size:.875rem; font-weight:600; color:#0f172a; }
      .tl-meta { display:flex; gap:.5rem; margin-top:.2rem; flex-wrap:wrap; align-items:center; }
      .tl-badge { font-size:.68rem; font-weight:600; padding:.2rem .5rem; border-radius:4px; text-transform:uppercase; }
      .tl-badge.manual { background:#eff6ff; color:#3b82f6; }
      .tl-badge.ia { background:#f0fdf4; color:#16a34a; }
      .tl-badge.canal { background:#f8fafc; color:#64748b; border:1px solid #e2e8f0; }
      .tl-date { font-size:.72rem; color:#94a3b8; }
      .tl-resumo { font-size:.82rem; color:#475569; margin-top:.3rem; line-height:1.5; }

      .interaction-form label { font-size:.75rem; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:.05em; display:block; margin-bottom:.3rem; }
      .interaction-form input, .interaction-form select, .interaction-form textarea {
        width:100%; padding:.55rem .8rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.85rem;
        color:#0f172a; background:#f8fafc; outline:none; transition:border-color .15s;
      }
      .interaction-form input:focus, .interaction-form select:focus, .interaction-form textarea:focus { border-color:#6366f1; background:#fff; }
      .form-field { margin-bottom:.9rem; }
      .tags-list { display:flex; flex-wrap:wrap; gap:.3rem; }
      .tag-chip { background:#f1f5f9; color:#475569; font-size:.72rem; font-weight:600; padding:.2rem .6rem; border-radius:20px; }
      .tag-chip.pdv { background:#fef9c3; color:#a16207; }
      .tag-chip.vip { background:#fce7f3; color:#9d174d; }
    </style>

    <div class="client-header">
      <div style="display:flex;gap:1rem;align-items:flex-start;flex:1;min-width:0;">
        <div class="client-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="client-meta">
          <h1><?= sanitize((string)$client['nome']) ?></h1>
          <?php if (!empty($client['email'])): ?><p class="email"><?= sanitize((string)$client['email']) ?></p><?php endif; ?>
          <div class="client-channels">
            <?php if ($waLink): ?>
              <a class="channel-btn channel-wa" target="_blank" href="<?= sanitize($waLink) ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                <?= sanitize($waNumber) ?>
              </a>
            <?php endif; ?>
            <?php if ($igLink): ?>
              <a class="channel-btn channel-ig" target="_blank" href="<?= sanitize($igLink) ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                @<?= sanitize($igUser) ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="client-actions">
        <a href="<?= sanitize(clients_url('action=edit&id='.(int)$id)) ?>" class="btn-sm btn-edit">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Editar
        </a>
        <a href="<?= sanitize(clients_url('action=delete&id='.(int)$id)) ?>" class="btn-sm btn-delete" onclick="return confirm('Excluir este cliente?')">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
          Excluir
        </a>
      </div>
    </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <p class="kpi-label">Pedidos</p>
        <p class="kpi-value"><?= (int)($orderStats['total_pedidos']??0) ?></p>
      </div>
      <div class="kpi-card">
        <p class="kpi-label">LTV Total</p>
        <p class="kpi-value indigo"><?= format_currency($orderStats['total_valor']??0) ?></p>
      </div>
      <div class="kpi-card">
        <p class="kpi-label">Último atendimento</p>
        <p class="kpi-value" style="font-size:1rem;margin-top:.4rem;"><?= !empty($client['ultimo_atendimento_em']) ? safe_dt((string)$client['ultimo_atendimento_em']) : '—' ?></p>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 300px;gap:1.25rem;align-items:start;">
      <div>
        <?php if (!empty($client['tags'])): ?>
        <div class="section-card" style="margin-bottom:1.25rem;">
          <div class="section-card-header"><h3>Tags</h3></div>
          <div class="section-card-body" style="padding:.85rem 1.25rem;">
            <div class="tags-list">
              <?php foreach(array_filter(array_map('trim',explode(',',$client['tags']))) as $t): ?>
                <span class="tag-chip <?= str_contains(strtolower($t),'pdv')?'pdv':(str_contains(strtolower($t),'vip')?'vip':'') ?>"><?= sanitize($t) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="section-card">
          <div class="section-card-header">
            <h3>Histórico de Atendimentos</h3>
            <span style="font-size:.75rem;color:#94a3b8;font-weight:500;"><?= count($interactions) ?> registros</span>
          </div>
          <div class="timeline" style="padding:0 1.25rem;">
            <?php if (empty($interactions)): ?>
              <div style="padding:2rem 0;text-align:center;color:#94a3b8;font-size:.875rem;">
                Nenhum atendimento registrado ainda.
              </div>
            <?php endif; ?>
            <?php foreach ($interactions as $it): $isIa = strtolower((string)($it['origem']??''))==='ia'; ?>
              <div class="timeline-item">
                <div class="tl-icon <?= $isIa?'ia':'manual' ?>">
                  <?php if($isIa): ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                  <?php else: ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                  <?php endif; ?>
                </div>
                <div class="tl-content">
                  <p class="tl-title"><?= sanitize((string)($it['titulo']??'')) ?></p>
                  <div class="tl-meta">
                    <span class="tl-badge <?= $isIa?'ia':'manual' ?>"><?= strtoupper(sanitize((string)($it['origem']??''))) ?></span>
                    <span class="tl-badge canal"><?= sanitize((string)($it['canal']??'')) ?></span>
                    <?php if(!empty($it['atendente'])): ?><span class="tl-date">por <?= sanitize((string)$it['atendente']) ?></span><?php endif; ?>
                    <span class="tl-date">· <?= sanitize(safe_dt($it['created_at']??null)) ?></span>
                  </div>
                  <?php if(!empty($it['resumo'])): ?><p class="tl-resumo"><?= sanitize((string)$it['resumo']) ?></p><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div>
        <div class="section-card">
          <div class="section-card-header"><h3>Registrar Atendimento</h3></div>
          <div class="section-card-body">
            <form method="POST" class="interaction-form">
              <input type="hidden" name="form" value="interaction">
              <div class="form-field">
                <label>Canal</label>
                <select name="canal">
                  <option value="whatsapp">WhatsApp</option>
                  <option value="instagram">Instagram</option>
                  <option value="telefone">Telefone</option>
                  <option value="presencial">Presencial</option>
                  <option value="outro">Outro</option>
                </select>
              </div>
              <div class="form-field">
                <label>Título *</label>
                <input name="titulo" placeholder="Ex.: Dúvida sobre produto" required>
              </div>
              <div class="form-field">
                <label>Resumo *</label>
                <textarea name="resumo" rows="4" placeholder="Descreva o atendimento..." required></textarea>
              </div>
              <button type="submit" class="btn-primary" style="width:100%;">Salvar atendimento</button>
            </form>
          </div>
        </div>

        <?php if(!empty($client['observacoes'])): ?>
        <div class="section-card" style="margin-top:1rem;">
          <div class="section-card-header"><h3>Observações</h3></div>
          <div class="section-card-body">
            <p style="font-size:.85rem;color:#475569;line-height:1.6;"><?= sanitize((string)$client['observacoes']) ?></p>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php include __DIR__ . '/views/partials/footer.php'; exit;
}

/* ===========================
   LISTAGEM — NOVO LAYOUT
   =========================== */
$search  = trim($_GET['q'] ?? '');
$tagFilter = trim($_GET['tag'] ?? '');
$page    = max(1,(int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = 'company_id = ?';
$params = [$companyId];

if ($search !== '') {
    $like = '%'.$search.'%';
    $where .= ' AND (nome LIKE ? OR telefone_principal LIKE ? OR whatsapp LIKE ? OR instagram_username LIKE ? OR tags LIKE ?)';
    $params = array_merge($params,[$like,$like,$like,$like,$like]);
}
if ($tagFilter !== '') {
    $where .= ' AND tags LIKE ?';
    $params[] = '%'.$tagFilter.'%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM clients WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$clients = $stmt->fetchAll();

$totalPages = max(1,(int)ceil($total/$perPage));

// Contar por tag para os chips de filtro
$tagCounts = [];
try {
    $tcStmt = $pdo->prepare("SELECT tags FROM clients WHERE company_id=? AND tags != ''");
    $tcStmt->execute([$companyId]);
    foreach($tcStmt->fetchAll() as $tr) {
        foreach(array_filter(array_map('trim',explode(',',$tr['tags']))) as $tg) {
            $tagCounts[$tg] = ($tagCounts[$tg]??0)+1;
        }
    }
    arsort($tagCounts);
} catch(Throwable $e) {}
?>

<style>
  /* ── Page layout ── */
  .cl-page { display:flex; flex-direction:column; gap:1.25rem; }

  /* ── Header bar ── */
  .cl-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; }
  .cl-topbar-left h1 { font-size:1.4rem; font-weight:700; color:#0f172a; }
  .cl-topbar-left p  { font-size:.82rem; color:#64748b; margin-top:.1rem; }
  .cl-topbar-actions { display:flex; gap:.6rem; flex-wrap:wrap; }

  .btn-primary { background:#6366f1; color:#fff; padding:.6rem 1.1rem; border:none; border-radius:9px; font-weight:600; font-size:.82rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:.4rem; transition:background .15s; white-space:nowrap; }
  .btn-primary:hover { background:#4f46e5; }
  .btn-outline { background:#fff; color:#334155; padding:.6rem 1.1rem; border:1.5px solid #e2e8f0; border-radius:9px; font-weight:600; font-size:.82rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:.4rem; transition:all .15s; white-space:nowrap; }
  .btn-outline:hover { border-color:#6366f1; color:#6366f1; background:#f5f3ff; }

  /* ── Stats strip ── */
  .cl-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
  .cl-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.25rem; }
  .cl-stat-label { font-size:.7rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; }
  .cl-stat-val { font-size:1.6rem; font-weight:700; color:#0f172a; margin-top:.15rem; }
  .cl-stat-val.indigo { color:#6366f1; }
  .cl-stat-val.green  { color:#16a34a; }
  .cl-stat-val.amber  { color:#d97706; }

  /* ── Search & filters ── */
  .cl-search-bar { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; }
  .cl-search-wrap { position:relative; flex:1; min-width:220px; max-width:420px; }
  .cl-search-wrap svg { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#94a3b8; pointer-events:none; }
  .cl-search-wrap input { width:100%; padding:.6rem .85rem .6rem 2.4rem; border:1.5px solid #e2e8f0; border-radius:9px; font-size:.875rem; color:#0f172a; background:#f8fafc; outline:none; transition:border-color .15s; }
  .cl-search-wrap input:focus { border-color:#6366f1; background:#fff; }

  .tag-filters { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
  .tag-filter-btn { padding:.3rem .7rem; border-radius:20px; font-size:.72rem; font-weight:600; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; text-decoration:none; transition:all .15s; display:inline-flex; align-items:center; gap:.3rem; white-space:nowrap; }
  .tag-filter-btn:hover, .tag-filter-btn.active { border-color:#6366f1; color:#6366f1; background:#f5f3ff; }
  .tag-filter-btn .count { background:#e0e7ff; color:#6366f1; border-radius:10px; padding:.05rem .35rem; font-size:.65rem; }

  /* ── Table ── */
  .cl-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
  .cl-table { width:100%; border-collapse:collapse; font-size:.875rem; }
  .cl-table thead th { padding:.85rem 1.1rem; text-align:left; font-size:.7rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; background:#f8fafc; border-bottom:1px solid #f1f5f9; white-space:nowrap; }
  .cl-table tbody tr { border-bottom:1px solid #f8fafc; transition:background .1s; }
  .cl-table tbody tr:last-child { border-bottom:none; }
  .cl-table tbody tr:hover { background:#fafafe; }
  .cl-table td { padding:.85rem 1.1rem; vertical-align:middle; }

  .cl-avatar-sm { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:.75rem; flex-shrink:0; }
  .cl-name-cell { display:flex; align-items:center; gap:.75rem; }
  .cl-name { font-weight:600; color:#0f172a; }
  .cl-sub  { font-size:.75rem; color:#94a3b8; margin-top:.1rem; }

  .wa-link { display:inline-flex; align-items:center; gap:.3rem; color:#16a34a; font-size:.8rem; font-weight:500; text-decoration:none; }
  .wa-link:hover { text-decoration:underline; }

  .tag-chip { display:inline-block; background:#f1f5f9; color:#475569; font-size:.7rem; font-weight:600; padding:.2rem .55rem; border-radius:20px; margin:.1rem; white-space:nowrap; }
  .tag-chip.pdv { background:#fef9c3; color:#a16207; }
  .tag-chip.vip { background:#fce7f3; color:#9d174d; }
  .tag-chip.inativo { background:#fee2e2; color:#dc2626; }

  .row-actions { display:flex; gap:.35rem; justify-content:flex-end; opacity:0; transition:opacity .15s; }
  tr:hover .row-actions { opacity:1; }
  .act-btn { padding:.3rem .6rem; border-radius:6px; font-size:.75rem; font-weight:600; text-decoration:none; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:.25rem; }
  .act-view { background:#f0fdf4; color:#16a34a; }
  .act-edit { background:#f1f5f9; color:#334155; }
  .act-del  { background:#fef2f2; color:#dc2626; }

  /* ── Empty state ── */
  .cl-empty { text-align:center; padding:3.5rem 1rem; color:#94a3b8; }
  .cl-empty svg { margin:0 auto 1rem; display:block; opacity:.35; }
  .cl-empty p { font-size:.9rem; }

  /* ── Pagination ── */
  .cl-pagination { display:flex; align-items:center; justify-content:space-between; padding:.85rem 1.1rem; border-top:1px solid #f1f5f9; flex-wrap:wrap; gap:.5rem; }
  .cl-pagination-info { font-size:.8rem; color:#94a3b8; }
  .cl-pages { display:flex; gap:.3rem; }
  .page-btn { width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:8px; border:1.5px solid #e2e8f0; font-size:.8rem; font-weight:600; color:#475569; text-decoration:none; transition:all .15s; }
  .page-btn:hover { border-color:#6366f1; color:#6366f1; }
  .page-btn.active { background:#6366f1; border-color:#6366f1; color:#fff; }

  /* ── CSV Import modal ── */
  .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:999; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
  .modal-overlay.open { display:flex; }
  .modal-box { background:#fff; border-radius:16px; padding:2rem; width:100%; max-width:480px; box-shadow:0 20px 60px rgba(0,0,0,.15); }
  .modal-box h2 { font-size:1.1rem; font-weight:700; color:#0f172a; margin-bottom:.4rem; }
  .modal-box p  { font-size:.82rem; color:#64748b; margin-bottom:1.5rem; line-height:1.5; }
  .upload-zone { border:2px dashed #e2e8f0; border-radius:12px; padding:2rem 1rem; text-align:center; cursor:pointer; transition:all .2s; background:#f8fafc; }
  .upload-zone:hover, .upload-zone.drag { border-color:#6366f1; background:#f5f3ff; }
  .upload-zone input { display:none; }
  .upload-zone svg { margin:0 auto .75rem; display:block; color:#94a3b8; }
  .upload-zone p { font-size:.82rem; color:#94a3b8; margin:0; }
  .upload-zone strong { color:#6366f1; }
  .upload-file-name { margin-top:.75rem; font-size:.8rem; font-weight:600; color:#334155; display:none; }
  .modal-footer { display:flex; gap:.6rem; margin-top:1.5rem; justify-content:flex-end; }
  .import-result { margin-top:1rem; padding:.85rem 1rem; border-radius:8px; font-size:.82rem; display:none; }
  .import-result.success { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
  .import-result.error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
</style>

<!-- MODAL DE IMPORTAÇÃO CSV -->
<div class="modal-overlay" id="csvModal">
  <div class="modal-box">
    <h2>Importar Clientes via CSV</h2>
    <p>Faça upload do arquivo <code style="background:#f1f5f9;padding:.1rem .4rem;border-radius:4px;font-size:.78rem;">clientes_whatsapp.csv</code>.<br>
    Chave única: <strong>WhatsApp + empresa</strong> — duplicatas são atualizadas, não duplicadas.</p>

    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('csvInput').click()">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <p><strong>Clique para selecionar</strong> ou arraste o arquivo aqui</p>
      <p style="margin-top:.25rem;">Formato: CSV com colunas id, nome, whatsapp</p>
      <input type="file" id="csvInput" accept=".csv">
    </div>
    <p class="upload-file-name" id="uploadFileName">📄 <span id="uploadFileNameText"></span></p>

    <div class="import-result" id="importResult"></div>

    <div class="modal-footer">
      <button class="btn-outline" onclick="document.getElementById('csvModal').classList.remove('open')">Cancelar</button>
      <button class="btn-primary" id="importBtn" onclick="runImport()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Importar
      </button>
    </div>
  </div>
</div>

<div class="cl-page">

  <!-- Topbar -->
  <div class="cl-topbar">
    <div class="cl-topbar-left">
      <h1>Clientes</h1>
      <p><?= number_format($total,0,'.','.') ?> cliente<?= $total!==1?'s':'' ?> cadastrado<?= $total!==1?'s':'' ?></p>
    </div>
    <div class="cl-topbar-actions">
      <button class="btn-outline" onclick="document.getElementById('csvModal').classList.add('open')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Importar CSV
      </button>
      <a href="<?= sanitize(clients_url('action=create')) ?>" class="btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo Cliente
      </a>
    </div>
  </div>

  <!-- Stats -->
  <?php
  $statsTotal   = $total;
  $statsRecent  = 0; $statsWa = 0; $statsPdv = 0;
  try {
    $statsRecent = (int)$pdo->prepare("SELECT COUNT(*) FROM clients WHERE company_id=? AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)")->execute([$companyId]) ? $pdo->query("SELECT COUNT(*) FROM clients WHERE company_id=$companyId AND created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetchColumn() : 0;
    $statsWa     = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE company_id=$companyId AND whatsapp != '' AND whatsapp IS NOT NULL")->fetchColumn();
    $statsPdv    = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE company_id=$companyId AND tags LIKE '%PDV_reativacao%'")->fetchColumn();
  } catch(Throwable $e) {}
  ?>
  <div class="cl-stats">
    <div class="cl-stat">
      <p class="cl-stat-label">Total</p>
      <p class="cl-stat-val"><?= number_format($statsTotal,0,'.','.') ?></p>
    </div>
    <div class="cl-stat">
      <p class="cl-stat-label">Novos (30d)</p>
      <p class="cl-stat-val green"><?= $statsRecent ?></p>
    </div>
    <div class="cl-stat">
      <p class="cl-stat-label">Com WhatsApp</p>
      <p class="cl-stat-val indigo"><?= $statsWa ?></p>
    </div>
    <div class="cl-stat">
      <p class="cl-stat-label">PDV Reativação</p>
      <p class="cl-stat-val amber"><?= $statsPdv ?></p>
    </div>
  </div>

  <!-- Search & tag filters -->
  <div style="display:flex;flex-direction:column;gap:.65rem;">
    <form method="GET" class="cl-search-bar">
      <div class="cl-search-wrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Buscar por nome, telefone, Instagram ou tags…">
      </div>
      <input type="hidden" name="tag" value="<?= sanitize($tagFilter) ?>">
      <button type="submit" class="btn-primary" style="padding:.6rem 1rem;">Buscar</button>
      <?php if($search||$tagFilter): ?>
        <a href="<?= sanitize(clients_url()) ?>" class="btn-outline" style="padding:.6rem 1rem;">Limpar</a>
      <?php endif; ?>
    </form>

    <?php if(!empty($tagCounts)): ?>
    <div class="tag-filters">
      <span style="font-size:.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Tags:</span>
      <a href="<?= sanitize(clients_url($search?'q='.urlencode($search):'')) ?>"
         class="tag-filter-btn <?= !$tagFilter?'active':'' ?>">Todos</a>
      <?php foreach(array_slice($tagCounts,0,8,true) as $tg=>$cnt): ?>
        <a href="<?= sanitize(clients_url('tag='.urlencode($tg).($search?'&q='.urlencode($search):''))) ?>"
           class="tag-filter-btn <?= $tagFilter===$tg?'active':'' ?>">
          <?= sanitize($tg) ?><span class="count"><?= $cnt ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Table -->
  <div class="cl-table-wrap">
    <?php if(empty($clients)): ?>
      <div class="cl-empty">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        <p><?= ($search||$tagFilter) ? 'Nenhum cliente encontrado para estes filtros.' : 'Nenhum cliente cadastrado ainda.' ?></p>
        <?php if(!$search&&!$tagFilter): ?>
          <a href="<?= sanitize(clients_url('action=create')) ?>" class="btn-primary" style="margin-top:1rem;display:inline-flex;">+ Novo Cliente</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <table class="cl-table">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>WhatsApp</th>
            <th>Instagram</th>
            <th>Tags</th>
            <th>Cadastro</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($clients as $c):
            $cInit = strtoupper(implode('',array_map(fn($w)=>$w[0],array_slice(explode(' ',trim((string)$c['nome'])),0,2))));
            $cWaNum = phone_digits($c['whatsapp']??'');
            $cWaLink = $cWaNum ? 'https://wa.me/'.$cWaNum : '';
          ?>
          <tr>
            <td>
              <div class="cl-name-cell">
                <div class="cl-avatar-sm"><?= htmlspecialchars($cInit) ?></div>
                <div>
                  <p class="cl-name"><?= sanitize((string)$c['nome']) ?></p>
                  <?php if(!empty($c['email'])): ?><p class="cl-sub"><?= sanitize((string)$c['email']) ?></p><?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?php if($cWaLink): ?>
                <a class="wa-link" href="<?= sanitize($cWaLink) ?>" target="_blank">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                  <?= sanitize($cWaNum) ?>
                </a>
              <?php else: ?><span style="color:#cbd5e1;font-size:.8rem;">—</span><?php endif; ?>
            </td>
            <td>
              <?php $cIg = ltrim((string)($c['instagram_username']??''),'@');
              if($cIg): ?>
                <a href="https://instagram.com/<?= rawurlencode($cIg) ?>" target="_blank" style="color:#be185d;font-size:.8rem;text-decoration:none;">@<?= sanitize($cIg) ?></a>
              <?php else: ?><span style="color:#cbd5e1;font-size:.8rem;">—</span><?php endif; ?>
            </td>
            <td>
              <?php if(!empty($c['tags'])):
                foreach(array_filter(array_map('trim',explode(',',$c['tags']))) as $tg):
                  $cls = str_contains(strtolower($tg),'pdv')?'pdv':(str_contains(strtolower($tg),'vip')?'vip':(str_contains(strtolower($tg),'inativo')?'inativo':''));
              ?>
                <span class="tag-chip <?= $cls ?>"><?= sanitize($tg) ?></span>
              <?php endforeach; else: ?>
                <span style="color:#cbd5e1;font-size:.8rem;">—</span>
              <?php endif; ?>
            </td>
            <td style="color:#94a3b8;font-size:.78rem;white-space:nowrap;">
              <?= !empty($c['created_at']) ? safe_dt((string)$c['created_at']) : '—' ?>
            </td>
            <td>
              <div class="row-actions">
                <a class="act-btn act-view" href="<?= sanitize(clients_url('action=view&id='.(int)$c['id'])) ?>">Ver</a>
                <a class="act-btn act-edit" href="<?= sanitize(clients_url('action=edit&id='.(int)$c['id'])) ?>">Editar</a>
                <a class="act-btn act-del"  href="<?= sanitize(clients_url('action=delete&id='.(int)$c['id'])) ?>" onclick="return confirm('Excluir cliente?')">Excluir</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <div class="cl-pagination">
        <p class="cl-pagination-info">
          Exibindo <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> de <?= number_format($total,0,'.','.') ?> clientes
        </p>
        <?php if($totalPages>1): ?>
        <div class="cl-pages">
          <?php
          $qs = ($search?'q='.urlencode($search).'&':'').($tagFilter?'tag='.urlencode($tagFilter).'&':'');
          $range = range(max(1,$page-2),min($totalPages,$page+2));
          if(!in_array(1,$range)) { echo '<a class="page-btn" href="'.sanitize(clients_url($qs.'page=1')).'">1</a>'; if($range[0]>2) echo '<span style="padding:0 .25rem;color:#94a3b8;">…</span>'; }
          foreach($range as $pg): ?>
            <a class="page-btn <?= $pg===$page?'active':'' ?>" href="<?= sanitize(clients_url($qs.'page='.$pg)) ?>"><?= $pg ?></a>
          <?php endforeach;
          $last = end($range);
          if($last<$totalPages) { if($last<$totalPages-1) echo '<span style="padding:0 .25rem;color:#94a3b8;">…</span>'; echo '<a class="page-btn" href="'.sanitize(clients_url($qs.'page='.$totalPages)).'">'.$totalPages.'</a>'; }
          ?>
        </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

</div><!-- .cl-page -->

<script>
// ── Upload zone drag & drop ──
const zone   = document.getElementById('uploadZone');
const input  = document.getElementById('csvInput');
const fnWrap = document.getElementById('uploadFileName');
const fnText = document.getElementById('uploadFileNameText');

['dragover','dragenter'].forEach(e => zone.addEventListener(e, ev => { ev.preventDefault(); zone.classList.add('drag'); }));
['dragleave','drop'].forEach(e => zone.addEventListener(e, () => zone.classList.remove('drag')));
zone.addEventListener('drop', ev => { ev.preventDefault(); if(ev.dataTransfer.files[0]) { input.files = ev.dataTransfer.files; showFile(ev.dataTransfer.files[0].name); } });
input.addEventListener('change', () => { if(input.files[0]) showFile(input.files[0].name); });

function showFile(name) {
  fnText.textContent = name;
  fnWrap.style.display = 'block';
}

// ── AJAX import ──
async function runImport() {
  const file = input.files[0];
  if (!file) { alert('Selecione um arquivo CSV primeiro.'); return; }

  const btn = document.getElementById('importBtn');
  const res = document.getElementById('importResult');
  btn.disabled = true;
  btn.textContent = 'Importando…';
  res.style.display = 'none';

  const fd = new FormData();
  fd.append('form', 'csv_import');
  fd.append('csv_file', file);

  try {
    const r = await fetch('clients.php', { method:'POST', body:fd });
    const json = await r.json();

    if (json.error) {
      res.className = 'import-result error';
      res.textContent = 'Erro: ' + json.error;
    } else {
      res.className = 'import-result success';
      res.innerHTML = `✓ Importação concluída — <strong>${json.inserted}</strong> inseridos · <strong>${json.updated}</strong> atualizados · <strong>${json.skipped}</strong> ignorados`;
      if (json.inserted > 0 || json.updated > 0) {
        setTimeout(() => location.reload(), 2000);
      }
    }
    res.style.display = 'block';
  } catch(e) {
    res.className = 'import-result error';
    res.textContent = 'Erro de comunicação com o servidor.';
    res.style.display = 'block';
  }

  btn.disabled = false;
  btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Importar';
}

// Fechar modal com ESC
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.getElementById('csvModal').classList.remove('open');
});
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>