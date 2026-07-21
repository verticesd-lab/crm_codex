<?php
/**
 * barber_services.php
 * ─────────────────────────────────────────────────────────────
 * Gerenciamento de serviços por barbeiro
 * Admin: vê todos os barbeiros e edita qualquer um
 * Barbeiro: vê e edita apenas os próprios serviços
 * ─────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo       = get_pdo();
$cid       = current_company_id();
$isAdmin   = !empty($_SESSION['is_admin']) || !empty($_SESSION['admin']);
$userId    = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

/* ── Migration automática ─────────────────────────────────── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS barber_service_overrides (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        company_id      INT NOT NULL,
        barber_id       INT NOT NULL,
        service_id      INT NOT NULL,
        preco           DECIMAL(10,2) NULL,
        duracao_min     INT NULL,
        ativo           TINYINT NOT NULL DEFAULT 1,
        updated_at      DATETIME DEFAULT NOW() ON UPDATE NOW(),
        UNIQUE KEY uq_barber_svc (company_id, barber_id, service_id),
        INDEX idx_barber (company_id, barber_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

/* ── Descobre barber_id do usuário logado (se não for admin) ─ */
$myBarberId = 0;
if (!$isAdmin) {
    try {
        $st = $pdo->prepare("SELECT id FROM barbers WHERE company_id=? AND user_id=? AND is_active=1 LIMIT 1");
        $st->execute([$cid, $userId]);
        $myBarberId = (int)($st->fetchColumn() ?: 0);
        if (!$myBarberId) {
            // Fallback: tenta pelo nome
            $st2 = $pdo->prepare("SELECT id FROM barbers WHERE company_id=? AND id=? LIMIT 1");
            $st2->execute([$cid, $userId]);
            $myBarberId = (int)($st2->fetchColumn() ?: $userId);
        }
    } catch (Throwable $e) { $myBarberId = $userId; }
}

/* ── Ações POST ──────────────────────────────────────────── */
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act      = $_POST['action'] ?? '';
    $barberId = (int)($_POST['barber_id'] ?? 0);
    $svcId    = (int)($_POST['service_id'] ?? 0);

    // Segurança: barbeiro só pode editar os próprios
    if (!$isAdmin && $barberId !== $myBarberId) {
        $msg = 'Acesso negado.'; $msgType = 'error';
    } elseif ($act === 'save_override') {
        $preco      = $_POST['preco']      !== '' ? (float)str_replace(',','.',$_POST['preco'])      : null;
        $duracao    = $_POST['duracao_min']!== '' ? (int)$_POST['duracao_min']                       : null;
        $ativo      = isset($_POST['ativo']) ? 1 : 0;

        try {
            $pdo->prepare("INSERT INTO barber_service_overrides
                (company_id, barber_id, service_id, preco, duracao_min, ativo)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    preco       = VALUES(preco),
                    duracao_min = VALUES(duracao_min),
                    ativo       = VALUES(ativo),
                    updated_at  = NOW()")
                ->execute([$cid, $barberId, $svcId, $preco, $duracao, $ativo]);
            $msg = '✅ Serviço atualizado com sucesso!';
        } catch (Throwable $e) {
            $msg = 'Erro ao salvar: '.$e->getMessage(); $msgType = 'error';
        }
    } elseif ($act === 'reset_override') {
        try {
            $pdo->prepare("DELETE FROM barber_service_overrides WHERE company_id=? AND barber_id=? AND service_id=?")
                ->execute([$cid, $barberId, $svcId]);
            $msg = '↺ Serviço restaurado para o padrão global.';
        } catch (Throwable $e) {
            $msg = 'Erro: '.$e->getMessage(); $msgType = 'error';
        }
    }
}

/* ── Carrega barbeiros ────────────────────────────────────── */
$barbers = [];
try {
    if ($isAdmin) {
        $st = $pdo->prepare("SELECT id, name FROM barbers WHERE company_id=? AND is_active=1 ORDER BY name");
        $st->execute([$cid]);
    } else {
        $st = $pdo->prepare("SELECT id, name FROM barbers WHERE company_id=? AND id=? AND is_active=1 LIMIT 1");
        $st->execute([$cid, $myBarberId]);
    }
    $barbers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ── Barbeiro selecionado ─────────────────────────────────── */
$selBarberId = (int)($_GET['barber_id'] ?? ($isAdmin ? ($barbers[0]['id'] ?? 0) : $myBarberId));

/* ── Carrega serviços com overrides ──────────────────────── */
$services = [];
if ($selBarberId) {
    try {
        $st = $pdo->prepare("
            SELECT
                s.id,
                s.label                AS nome,
                s.price                AS preco_global,
                s.duration_minutes     AS duracao_global,
                o.preco                AS preco_override,
                o.duracao_min          AS duracao_override,
                o.ativo                AS ativo_override,
                (o.id IS NOT NULL)     AS tem_override
            FROM services s
            LEFT JOIN barber_service_overrides o
                ON  o.service_id  = s.id
                AND o.barber_id   = ?
                AND o.company_id  = ?
            WHERE s.company_id = ?
              AND s.is_active   = 1
            ORDER BY s.label ASC
        ");
        $st->execute([$selBarberId, $cid, $cid]);
        $services = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

include __DIR__ . '/views/partials/header.php';
?>
<style>
.bs-page { font-family:'Inter',system-ui,sans-serif; color:#0f172a; max-width:1100px; margin:0 auto; padding:1.5rem 1rem; }
.bs-page *{ box-sizing:border-box; }

/* Header */
.bs-hd { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; margin-bottom:1.5rem; }
.bs-hd h1 { font-size:1.2rem; font-weight:800; }
.bs-hd p  { font-size:.78rem; color:#64748b; margin-top:.1rem; }

/* Barbeiros tabs */
.bs-tabs { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.bs-tab  { display:flex; align-items:center; gap:.5rem; padding:.5rem 1rem; border-radius:10px;
           border:1.5px solid #e2e8f0; background:#f8fafc; text-decoration:none; color:#475569;
           font-size:.82rem; font-weight:600; transition:all .15s; }
.bs-tab:hover  { border-color:#6366f1; color:#6366f1; }
.bs-tab.active { background:#6366f1; border-color:#6366f1; color:#fff; }
.bs-tab img    { width:28px; height:28px; border-radius:50%; object-fit:cover; background:#e2e8f0; }
.bs-tab-av     { width:28px; height:28px; border-radius:50%; background:#e2e8f0;
                 display:flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:700; color:#6366f1; }

/* Alert */
.bs-alert { padding:.75rem 1rem; border-radius:10px; font-size:.82rem; font-weight:600; margin-bottom:1rem; }
.bs-alert.success { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
.bs-alert.error   { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }

/* Legenda */
.bs-legend { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; font-size:.75rem; color:#64748b; }
.bs-legend span { display:flex; align-items:center; gap:.3rem; }
.dot { width:8px; height:8px; border-radius:50%; display:inline-block; }

/* Tabela */
.bs-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.bs-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.bs-table th { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
               color:#94a3b8; padding:.65rem 1rem; background:#f8fafc; border-bottom:1px solid #f1f5f9;
               text-align:left; white-space:nowrap; }
.bs-table td { padding:.7rem 1rem; border-bottom:1px solid #f8fafc; vertical-align:middle; }
.bs-table tr:last-child td { border-bottom:none; }
.bs-table tr:hover td { background:#fafafe; }
.bs-table tr.inativo td { opacity:.5; }

/* Badge */
.bs-badge { display:inline-flex; align-items:center; gap:.25rem; font-size:.65rem; font-weight:700;
            padding:.2rem .5rem; border-radius:20px; white-space:nowrap; }
.bs-badge.custom  { background:#ede9fe; color:#6d28d9; }
.bs-badge.global  { background:#f1f5f9; color:#64748b; }
.bs-badge.off     { background:#fee2e2; color:#dc2626; }

/* Inputs inline */
.bs-inp { padding:.4rem .65rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.82rem;
          width:100px; outline:none; background:#f8fafc; font-family:inherit; }
.bs-inp:focus { border-color:#6366f1; background:#fff; }
.bs-inp.wide  { width:70px; }

/* Botões */
.bs-btn { padding:.35rem .85rem; border-radius:8px; font-size:.75rem; font-weight:700;
          cursor:pointer; border:none; transition:all .15s; white-space:nowrap; }
.bs-btn.primary { background:#6366f1; color:#fff; }
.bs-btn.primary:hover { background:#4f46e5; }
.bs-btn.ghost   { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
.bs-btn.ghost:hover { border-color:#6366f1; color:#6366f1; }
.bs-btn.danger  { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.bs-btn.danger:hover { background:#fee2e2; }

/* Toggle */
.bs-toggle { position:relative; display:inline-flex; align-items:center; cursor:pointer; gap:.4rem; }
.bs-toggle input { opacity:0; width:0; height:0; position:absolute; }
.bs-slider { width:34px; height:18px; background:#e2e8f0; border-radius:20px; position:relative; transition:.2s; }
.bs-slider::after { content:''; position:absolute; top:2px; left:2px; width:14px; height:14px;
                    background:#fff; border-radius:50%; transition:.2s; }
.bs-toggle input:checked + .bs-slider { background:#6366f1; }
.bs-toggle input:checked + .bs-slider::after { left:18px; }

/* Row editor expandido */
.bs-editor { background:#f5f3ff; border-top:1px dashed #c4b5fd; padding:1rem; display:none; }
.bs-editor.open { display:block; }
.bs-editor-grid { display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:.75rem; align-items:end; }
.bs-editor label { font-size:.65rem; font-weight:700; text-transform:uppercase; color:#94a3b8;
                   display:block; margin-bottom:.25rem; }
.bs-editor input[type=number], .bs-editor input[type=text] {
    width:100%; padding:.5rem .75rem; border:1.5px solid #e2e8f0; border-radius:8px;
    font-size:.82rem; outline:none; background:#fff; }
.bs-editor input:focus { border-color:#6366f1; }
.bs-editor-actions { display:flex; gap:.5rem; padding-top:1.5rem; }

@media(max-width:700px){
    .bs-editor-grid { grid-template-columns:1fr 1fr; }
    .bs-table th:nth-child(4), .bs-table td:nth-child(4) { display:none; }
}
</style>

<div class="bs-page">

<!-- Header -->
<div class="bs-hd">
    <div>
        <h1>✂️ Serviços por Barbeiro</h1>
        <p>Personalize preço e duração de cada serviço por barbeiro. O padrão global é mantido quando não há personalização.</p>
    </div>
    <?php if ($isAdmin): ?>
    <a href="services_admin.php" class="bs-btn ghost" style="text-decoration:none;">⚙️ Editar serviços globais</a>
    <?php endif; ?>
</div>

<!-- Mensagem de retorno -->
<?php if ($msg): ?>
<div class="bs-alert <?= $msgType ?>"><?= sanitize($msg) ?></div>
<?php endif; ?>

<!-- Tabs de barbeiros -->
<?php if (!empty($barbers)): ?>
<div class="bs-tabs">
    <?php foreach ($barbers as $b): ?>
    <?php
        $words    = array_filter(explode(' ', trim($b['name'] ?? '?')));
        $initials = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', array_slice($words, 0, 2))));
    ?>
    <a href="?barber_id=<?= $b['id'] ?>"
       class="bs-tab <?= $selBarberId === (int)$b['id'] ? 'active' : '' ?>">
        <div class="bs-tab-av"><?= sanitize($initials) ?></div>
        <?= sanitize($b['name']) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Legenda -->
<div class="bs-legend">
    <span><span class="dot" style="background:#6366f1"></span> Personalizado para este barbeiro</span>
    <span><span class="dot" style="background:#94a3b8"></span> Usando padrão global</span>
    <span><span class="dot" style="background:#dc2626"></span> Desativado para este barbeiro</span>
</div>

<!-- Tabela de serviços -->
<?php if ($selBarberId && !empty($services)): ?>
<div class="bs-table-wrap">
    <table class="bs-table">
        <thead><tr>
            <th>Serviço</th>
            <th>Preço</th>
            <th>Duração</th>
            <th>Status</th>
            <th style="width:120px;text-align:center;">Ações</th>
        </tr></thead>
        <tbody>
        <?php foreach ($services as $svc):
            $temOverride  = (bool)$svc['tem_override'];
            $ativoOvr     = $temOverride ? (bool)$svc['ativo_override'] : true;
            $precoFinal   = $temOverride && $svc['preco_override'] !== null
                            ? (float)$svc['preco_override'] : (float)$svc['preco_global'];
            $duracaoFinal = $temOverride && $svc['duracao_override'] !== null
                            ? (int)$svc['duracao_override'] : (int)$svc['duracao_global'];
            $rowId        = 'svc-'.$svc['id'];
        ?>
        <tr class="<?= !$ativoOvr ? 'inativo' : '' ?>" id="row-<?= $rowId ?>">
            <td>
                <div style="font-weight:700;color:#0f172a;"><?= sanitize($svc['nome']) ?></div>
                <?php if ($temOverride): ?>
                    <span class="bs-badge custom">✦ Personalizado</span>
                <?php else: ?>
                    <span class="bs-badge global">Padrão global</span>
                <?php endif; ?>
            </td>
            <td>
                <div style="font-weight:700;color:#0f172a;">
                    R$ <?= number_format($precoFinal, 2, ',', '.') ?>
                </div>
                <?php if ($temOverride && $svc['preco_override'] !== null): ?>
                <div style="font-size:.68rem;color:#94a3b8;text-decoration:line-through;">
                    Global: R$ <?= number_format($svc['preco_global'],2,',','.') ?>
                </div>
                <?php endif; ?>
            </td>
            <td>
                <div style="font-weight:600;"><?= $duracaoFinal ?> min</div>
                <?php if ($temOverride && $svc['duracao_override'] !== null): ?>
                <div style="font-size:.68rem;color:#94a3b8;text-decoration:line-through;">
                    Global: <?= $svc['duracao_global'] ?> min
                </div>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$ativoOvr): ?>
                    <span class="bs-badge off">⊘ Desativado</span>
                <?php else: ?>
                    <span style="font-size:.75rem;color:#16a34a;font-weight:600;">✓ Ativo</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <div style="display:flex;gap:.35rem;justify-content:center;">
                    <button class="bs-btn ghost"
                        onclick="toggleEditor('<?= $rowId ?>')"
                        style="font-size:.72rem;">✏️ Editar</button>
                    <?php if ($temOverride): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Restaurar padrão global?')">
                        <input type="hidden" name="action"     value="reset_override">
                        <input type="hidden" name="barber_id"  value="<?= $selBarberId ?>">
                        <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
                        <button type="submit" class="bs-btn danger" style="font-size:.72rem;">↺</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <!-- Editor expandido -->
        <tr id="editor-<?= $rowId ?>">
            <td colspan="5" style="padding:0;border-bottom:1px solid #f1f5f9;">
                <div class="bs-editor" id="editordiv-<?= $rowId ?>">
                    <form method="POST">
                        <input type="hidden" name="action"     value="save_override">
                        <input type="hidden" name="barber_id"  value="<?= $selBarberId ?>">
                        <input type="hidden" name="service_id" value="<?= $svc['id'] ?>">
                        <div class="bs-editor-grid">
                            <div>
                                <label>Preço personalizado (R$)</label>
                                <input type="number" name="preco" step="0.01" min="0"
                                    value="<?= $temOverride && $svc['preco_override'] !== null ? number_format($svc['preco_override'],2,'.','') : '' ?>"
                                    placeholder="<?= number_format($svc['preco_global'],2,'.',',') ?> (global)">
                                <p style="font-size:.65rem;color:#94a3b8;margin:.2rem 0 0;">Vazio = usa o global</p>
                            </div>
                            <div>
                                <label>Duração personalizada (min)</label>
                                <input type="number" name="duracao_min" min="5" step="5"
                                    value="<?= $temOverride && $svc['duracao_override'] !== null ? $svc['duracao_override'] : '' ?>"
                                    placeholder="<?= $svc['duracao_global'] ?> min (global)">
                                <p style="font-size:.65rem;color:#94a3b8;margin:.2rem 0 0;">Vazio = usa o global</p>
                            </div>
                            <div>
                                <label>Disponível para este barbeiro</label>
                                <label class="bs-toggle" style="margin-top:.4rem;">
                                    <input type="checkbox" name="ativo" value="1"
                                        <?= (!$temOverride || $ativoOvr) ? 'checked' : '' ?>>
                                    <span class="bs-slider"></span>
                                    <span style="font-size:.78rem;color:#475569;">Ativo</span>
                                </label>
                            </div>
                            <div class="bs-editor-actions">
                                <button type="submit" class="bs-btn primary">💾 Salvar</button>
                                <button type="button" class="bs-btn ghost"
                                    onclick="toggleEditor('<?= $rowId ?>')">Cancelar</button>
                            </div>
                        </div>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($selBarberId): ?>
<div style="text-align:center;padding:3rem;color:#94a3b8;background:#fff;border:1px solid #e2e8f0;border-radius:14px;">
    Nenhum serviço cadastrado ainda. <a href="services_admin.php" style="color:#6366f1;">Cadastrar serviços globais →</a>
</div>
<?php else: ?>
<div style="text-align:center;padding:3rem;color:#94a3b8;background:#fff;border:1px solid #e2e8f0;border-radius:14px;">
    Selecione um barbeiro acima para ver seus serviços.
</div>
<?php endif; ?>

</div>

<script>
function toggleEditor(id) {
    const div = document.getElementById('editordiv-' + id);
    if (!div) return;
    div.classList.toggle('open');
    // Fecha os outros
    document.querySelectorAll('.bs-editor.open').forEach(d => {
        if (d.id !== 'editordiv-' + id) d.classList.remove('open');
    });
}
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>