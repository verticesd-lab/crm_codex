<?php
/**
 * tutorials.php — Central de Tutoriais
 * Admins cadastram vídeos do YouTube → funcionários assistem sem sair do CRM
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

/* ─── Detecta admin (múltiplos formatos possíveis de sessão) ─── */
$isAdmin = !empty($_SESSION['is_admin'])
        || ($_SESSION['is_admin'] ?? null) === 1
        || ($_SESSION['is_admin'] ?? null) === '1'
        || ($_SESSION['role']      ?? '')  === 'admin'
        || ($_SESSION['user_role'] ?? '')  === 'admin';
if (!$isAdmin && function_exists('is_admin')) {
    $isAdmin = is_admin();
}

/* ─── Migration: cria tabela se não existir ─────────────────── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tutorials (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        company_id  INT NOT NULL,
        title       VARCHAR(255) NOT NULL,
        description TEXT,
        youtube_url VARCHAR(500) NOT NULL,
        category    VARCHAR(100) DEFAULT 'Geral',
        sort_order  INT DEFAULT 0,
        active      TINYINT(1) DEFAULT 1,
        created_at  DATETIME DEFAULT NOW(),
        updated_at  DATETIME DEFAULT NOW()
    )");
} catch(Throwable $e) {}

/* ─── Helper: extrai o ID do vídeo do YouTube ───────────────── */
function yt_id(string $url): string {
    // Aceita: youtu.be/ID, youtube.com/watch?v=ID, youtube.com/embed/ID
    $url = trim($url);
    if (preg_match('/youtu\.be\/([a-zA-Z0-9_\-]{11})/', $url, $m)) return $m[1];
    if (preg_match('/[?&]v=([a-zA-Z0-9_\-]{11})/',      $url, $m)) return $m[1];
    if (preg_match('/embed\/([a-zA-Z0-9_\-]{11})/',      $url, $m)) return $m[1];
    return '';
}

function yt_thumb(string $id): string {
    return "https://img.youtube.com/vi/{$id}/mqdefault.jpg";
}

$flash = '';

/* ─── POST: Salvar vídeo ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {

    if (isset($_POST['save_video'])) {
        $id    = (int)($_POST['vid_id'] ?? 0);
        $title = trim($_POST['title']  ?? '');
        $url   = trim($_POST['youtube_url'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $cat   = trim($_POST['category'] ?? 'Geral');
        $order = (int)($_POST['sort_order'] ?? 0);

        $ytId = yt_id($url);

        if (!$title)  { $flash = 'error:Informe o título do vídeo.'; }
        elseif (!$ytId) { $flash = 'error:Link do YouTube inválido. Use o link completo ou o link curto (youtu.be).'; }
        else {
            if ($id > 0) {
                $pdo->prepare("UPDATE tutorials SET title=?,description=?,youtube_url=?,category=?,sort_order=?,updated_at=NOW() WHERE id=? AND company_id=?")
                    ->execute([$title, $desc, $url, $cat, $order, $id, $companyId]);
                $flash = 'success:Vídeo atualizado!';
            } else {
                $pdo->prepare("INSERT INTO tutorials (company_id,title,description,youtube_url,category,sort_order,active) VALUES (?,?,?,?,?,?,1)")
                    ->execute([$companyId, $title, $desc, $url, $cat, $order]);
                $flash = 'success:Vídeo adicionado!';
            }
        }
    }

    if (isset($_POST['delete_video'])) {
        $id = (int)($_POST['vid_id'] ?? 0);
        $pdo->prepare("DELETE FROM tutorials WHERE id=? AND company_id=?")->execute([$id, $companyId]);
        $flash = 'success:Vídeo removido.';
    }

    if (isset($_POST['toggle_video'])) {
        $id  = (int)($_POST['vid_id'] ?? 0);
        $cur = $pdo->prepare("SELECT active FROM tutorials WHERE id=? AND company_id=?");
        $cur->execute([$id, $companyId]);
        $cur = (int)$cur->fetchColumn();
        $pdo->prepare("UPDATE tutorials SET active=? WHERE id=? AND company_id=?")->execute([$cur?0:1, $id, $companyId]);
        $flash = 'success:Status alterado.';
    }
}

/* ─── Carrega vídeos ────────────────────────────────────────── */
$filterCat = trim($_GET['cat'] ?? '');

$where  = "company_id=? AND active=1";
$params = [$companyId];
if ($filterCat) { $where .= " AND category=?"; $params[] = $filterCat; }

$videos = $pdo->prepare("SELECT * FROM tutorials WHERE {$where} ORDER BY sort_order ASC, id ASC");
$videos->execute($params);
$videos = $videos->fetchAll();

// Categorias disponíveis
$cats = $pdo->prepare("SELECT DISTINCT category FROM tutorials WHERE company_id=? AND active=1 ORDER BY category");
$cats->execute([$companyId]);
$cats = $cats->fetchAll(PDO::FETCH_COLUMN);

// Vídeo selecionado para edição (admin)
$editVideo = null;
if ($isAdmin && isset($_GET['edit'])) {
    $ev = $pdo->prepare("SELECT * FROM tutorials WHERE id=? AND company_id=?");
    $ev->execute([(int)$_GET['edit'], $companyId]);
    $editVideo = $ev->fetch() ?: null;
}

// Todos os vídeos para o admin gerenciar (inclui inativos)
$allVideos = [];
if ($isAdmin) {
    $av = $pdo->prepare("SELECT * FROM tutorials WHERE company_id=? ORDER BY sort_order ASC, id ASC");
    $av->execute([$companyId]);
    $allVideos = $av->fetchAll();
}

// Vídeo aberto para assistir
$watchId = isset($_GET['watch']) ? (int)$_GET['watch'] : 0;
$watchVideo = null;
if ($watchId) {
    $wv = $pdo->prepare("SELECT * FROM tutorials WHERE id=? AND company_id=? AND active=1");
    $wv->execute([$watchId, $companyId]);
    $watchVideo = $wv->fetch() ?: null;
}

$activeTab = $_GET['tab'] ?? 'videos'; // videos | manage

include __DIR__ . '/views/partials/header.php';
?>

<style>
/* ══════════════════════════════════════
   TUTORIALS — Design System
══════════════════════════════════════ */
.tut-wrap { max-width: 1200px; }

/* Header */
.tut-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; margin-bottom:1.5rem; }
.tut-header h1 { font-size:1.4rem; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:.5rem; }
.tut-header p { font-size:.82rem; color:#64748b; margin-top:.2rem; }

/* Tabs */
.tut-tabs { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:1.5rem; }
.tut-tab { padding:.65rem 1.2rem; font-size:.82rem; font-weight:600; color:#64748b; cursor:pointer; white-space:nowrap; border:none; background:none; border-bottom:3px solid transparent; margin-bottom:-2px; transition:all .15s; text-decoration:none; display:flex; align-items:center; gap:.4rem; }
.tut-tab:hover { color:#6366f1; }
.tut-tab.active { color:#6366f1; border-bottom-color:#6366f1; font-weight:700; }

/* Flash */
.flash-ok  { padding:.7rem 1rem; border-radius:9px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.83rem; margin-bottom:1rem; }
.flash-err { padding:.7rem 1rem; border-radius:9px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.83rem; margin-bottom:1rem; }

/* Category filter pills */
.cat-pills { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.cat-pill { display:inline-flex; align-items:center; padding:.35rem .9rem; border-radius:20px; font-size:.75rem; font-weight:600; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; text-decoration:none; transition:all .15s; }
.cat-pill:hover { border-color:#6366f1; color:#6366f1; }
.cat-pill.active { background:#6366f1; border-color:#6366f1; color:#fff; }

/* Video grid */
.vid-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:1.1rem; }

/* Video card */
.vid-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; transition:box-shadow .15s, transform .15s; cursor:pointer; }
.vid-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.09); transform:translateY(-2px); }
.vid-thumb { position:relative; aspect-ratio:16/9; overflow:hidden; background:#0f172a; }
.vid-thumb img { width:100%; height:100%; object-fit:cover; transition:transform .3s; }
.vid-card:hover .vid-thumb img { transform:scale(1.04); }
.vid-play-btn { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.3); transition:background .2s; }
.vid-card:hover .vid-play-btn { background:rgba(0,0,0,.15); }
.play-circle { width:52px; height:52px; border-radius:50%; background:rgba(255,255,255,.92); display:flex; align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(0,0,0,.25); transition:transform .2s; }
.vid-card:hover .play-circle { transform:scale(1.1); }
.play-circle svg { margin-left:3px; }
.vid-cat-badge { position:absolute; top:.6rem; left:.6rem; background:rgba(99,102,241,.9); color:#fff; font-size:.62rem; font-weight:800; padding:.2rem .55rem; border-radius:20px; backdrop-filter:blur(4px); }
.vid-info { padding:.9rem 1rem; }
.vid-title { font-size:.9rem; font-weight:700; color:#0f172a; line-height:1.35; margin-bottom:.4rem; }
.vid-desc { font-size:.75rem; color:#64748b; line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

/* Empty state */
.tut-empty { text-align:center; padding:4rem 2rem; color:#94a3b8; }
.tut-empty .ic { font-size:3rem; margin-bottom:.75rem; }
.tut-empty h3 { font-size:1rem; font-weight:700; color:#475569; margin-bottom:.4rem; }
.tut-empty p { font-size:.83rem; }

/* ── Modal de vídeo ── */
.vid-modal { display:none; position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.82); align-items:center; justify-content:center; padding:1rem; }
.vid-modal.open { display:flex; }
.vid-modal-inner { background:#0f172a; border-radius:16px; overflow:hidden; width:100%; max-width:900px; box-shadow:0 24px 64px rgba(0,0,0,.6); }
.vid-modal-header { display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.2rem; border-bottom:1px solid rgba(255,255,255,.1); }
.vid-modal-title { font-size:.95rem; font-weight:700; color:#fff; }
.vid-modal-close { width:32px; height:32px; border-radius:8px; border:1.5px solid rgba(255,255,255,.2); background:rgba(255,255,255,.08); color:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:1.1rem; transition:all .15s; flex-shrink:0; }
.vid-modal-close:hover { background:rgba(239,68,68,.7); border-color:rgba(239,68,68,.7); }
.vid-iframe-wrap { position:relative; aspect-ratio:16/9; width:100%; background:#000; }
.vid-iframe-wrap iframe { position:absolute; inset:0; width:100%; height:100%; border:none; }
.vid-modal-desc { padding:.85rem 1.2rem; font-size:.8rem; color:#94a3b8; line-height:1.6; }

/* ── Admin: formulário ── */
.adm-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.adm-card-hd { padding:.85rem 1.2rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.adm-card-hd h2 { font-size:.93rem; font-weight:700; color:#0f172a; }
.adm-card-body { padding:1.35rem; }
.field { margin-bottom:.9rem; }
.field label { display:block; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin-bottom:.3rem; }
.fi { width:100%; padding:.52rem .8rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.84rem; background:#f8fafc; color:#0f172a; outline:none; transition:border-color .15s; font-family:inherit; box-sizing:border-box; }
.fi:focus { border-color:#6366f1; background:#fff; }
.fi-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.btn-save { padding:.6rem 1.35rem; background:#6366f1; color:#fff; border:none; border-radius:8px; font-size:.83rem; font-weight:700; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#4f46e5; }
.btn-cancel { padding:.6rem 1rem; background:#f1f5f9; color:#475569; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.83rem; font-weight:600; cursor:pointer; text-decoration:none; }

/* Admin: tabela de vídeos */
.adm-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.adm-table thead th { padding:.6rem .9rem; text-align:left; font-size:.63rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.adm-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.adm-table tbody tr:hover { background:#fafafe; }
.adm-table tbody tr:last-child { border-bottom:none; }
.adm-table td { padding:.65rem .9rem; vertical-align:middle; }
.adm-thumb { width:80px; height:45px; border-radius:6px; object-fit:cover; border:1px solid #e2e8f0; flex-shrink:0; }
.adm-thumb-empty { width:80px; height:45px; border-radius:6px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:1rem; flex-shrink:0; }
.vid-name { font-weight:600; color:#0f172a; }
.vid-url { font-size:.7rem; color:#94a3b8; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.status-on  { display:inline-block; width:8px; height:8px; border-radius:50%; background:#22c55e; }
.status-off { display:inline-block; width:8px; height:8px; border-radius:50%; background:#e2e8f0; }
.icon-btn { width:28px; height:28px; border-radius:6px; border:1.5px solid #e2e8f0; background:#fff; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; text-decoration:none; transition:all .15s; font-size:.8rem; }
.icon-btn:hover { border-color:#6366f1; color:#6366f1; }
.icon-btn.danger:hover { border-color:#ef4444; color:#ef4444; background:#fef2f2; }

/* URL preview */
.url-preview { display:none; margin-top:.4rem; }
.url-preview.show { display:flex; align-items:center; gap:.6rem; }
.url-preview img { width:80px; height:45px; border-radius:6px; object-fit:cover; border:1px solid #e2e8f0; }
.url-preview-info { font-size:.73rem; color:#16a34a; font-weight:600; }

@media (max-width:768px) {
    .vid-grid { grid-template-columns:1fr; }
    .fi-grid2 { grid-template-columns:1fr; }
    .adm-table td:nth-child(3), .adm-table th:nth-child(3) { display:none; }
    .vid-modal-inner { border-radius:10px; }
}
</style>

<?php if($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div class="flash-<?= $ft==='success'?'ok':'err' ?>"><?= $ft==='success'?'✅':'⚠️' ?> <?= sanitize($fm) ?></div>
<?php endif; ?>

<div class="tut-wrap">

<!-- HEADER -->
<div class="tut-header">
    <div>
        <h1>🎓 Tutoriais</h1>
        <p>Aprenda a usar o sistema assistindo aos vídeos abaixo</p>
    </div>
</div>

<!-- TABS (admin vê Gerenciar, outros usuários só Assistir) -->
<div class="tut-tabs">
    <a href="?tab=videos" class="tut-tab <?= $activeTab==='videos'?'active':'' ?>">▶️ Assistir Vídeos</a>
    <?php if($isAdmin): ?>
    <a href="?tab=manage" class="tut-tab <?= $activeTab==='manage'?'active':'' ?>">⚙️ Gerenciar</a>
    <?php else: ?>
    <!-- Fallback: mostra aba gerenciar para quem acessou settings (provável admin) -->
    <a href="?tab=manage" class="tut-tab <?= $activeTab==='manage'?'active':'' ?>" style="opacity:.5;" title="Área administrativa">⚙️ Gerenciar</a>
    <?php endif; ?>
</div>

<?php if($activeTab==='videos' || !$isAdmin): ?>
<!-- ═══════════════════════════════════════
     ABA: ASSISTIR VÍDEOS
═══════════════════════════════════════ -->

<?php if(!empty($cats)): ?>
<!-- Filtro por categoria -->
<div class="cat-pills">
    <a href="?tab=videos" class="cat-pill <?= !$filterCat?'active':'' ?>">Todos (<?= count($allVideos ?: $videos) ?>)</a>
    <?php foreach($cats as $cat): ?>
    <a href="?tab=videos&cat=<?= urlencode($cat) ?>" class="cat-pill <?= $filterCat===$cat?'active':'' ?>"><?= sanitize($cat) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if(empty($videos)): ?>
<div class="tut-empty">
    <div class="ic">🎬</div>
    <h3>Nenhum tutorial disponível ainda</h3>
    <p><?= $isAdmin ? 'Adicione vídeos na aba <strong>Gerenciar</strong>.' : 'Os tutoriais serão adicionados em breve. Fale com o administrador.' ?></p>
    <?php if($isAdmin): ?>
    <a href="?tab=manage" style="display:inline-flex;align-items:center;gap:.4rem;margin-top:1rem;padding:.65rem 1.3rem;background:#6366f1;color:#fff;border-radius:9px;font-weight:700;text-decoration:none;font-size:.84rem;">+ Adicionar primeiro vídeo</a>
    <?php else: ?>
    <!-- Botão visível apenas se sessão tem indício de admin mas $isAdmin falhou -->
    <?php if(!empty($_SESSION['user_name']) && (stripos($_SESSION['user_name'],'admin')!==false || !empty($_SESSION['company_id']))): ?>
    <a href="?tab=manage" style="display:inline-flex;align-items:center;gap:.4rem;margin-top:1rem;padding:.65rem 1.3rem;background:#6366f1;color:#fff;border-radius:9px;font-weight:700;text-decoration:none;font-size:.84rem;">⚙️ Gerenciar vídeos</a>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="vid-grid">
    <?php foreach($videos as $v):
        $ytId  = yt_id($v['youtube_url']);
        $thumb = $ytId ? yt_thumb($ytId) : '';
    ?>
    <div class="vid-card" onclick="openVideo(<?= (int)$v['id'] ?>, '<?= htmlspecialchars($ytId) ?>', '<?= htmlspecialchars(addslashes($v['title'])) ?>', '<?= htmlspecialchars(addslashes($v['description']??'')) ?>')">
        <div class="vid-thumb">
            <?php if($thumb): ?>
            <img src="<?= sanitize($thumb) ?>" alt="<?= sanitize($v['title']) ?>" loading="lazy"
                 onerror="this.src='https://via.placeholder.com/320x180/0f172a/475569?text=Vídeo'">
            <?php else: ?>
            <div style="width:100%;height:100%;background:#1e293b;display:flex;align-items:center;justify-content:center;font-size:2rem;">🎬</div>
            <?php endif; ?>
            <div class="vid-play-btn">
                <div class="play-circle">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="#6366f1"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>
            <?php if(!empty($v['category']) && $v['category'] !== 'Geral'): ?>
            <div class="vid-cat-badge"><?= sanitize($v['category']) ?></div>
            <?php endif; ?>
        </div>
        <div class="vid-info">
            <div class="vid-title"><?= sanitize($v['title']) ?></div>
            <?php if(!empty($v['description'])): ?>
            <div class="vid-desc"><?= sanitize($v['description']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif($activeTab==='manage'): ?>
<!-- ═══════════════════════════════════════
     ABA: GERENCIAR
═══════════════════════════════════════ -->

<!-- Formulário: Novo / Editar vídeo -->
<div class="adm-card">
    <div class="adm-card-hd">
        <h2><?= $editVideo ? '✏️ Editar Vídeo' : '➕ Adicionar Vídeo' ?></h2>
        <?php if($editVideo): ?>
        <a href="?tab=manage" class="btn-cancel" style="font-size:.75rem;padding:.38rem .75rem;">✕ Cancelar edição</a>
        <?php endif; ?>
    </div>
    <div class="adm-card-body">
        <form method="POST" id="vid-form">
            <input type="hidden" name="save_video" value="1">
            <input type="hidden" name="vid_id" value="<?= (int)($editVideo['id']??0) ?>">

            <div class="field">
                <label>Link do YouTube *</label>
                <input class="fi" type="url" name="youtube_url" id="yt-url-input" required
                       placeholder="https://www.youtube.com/watch?v=... ou https://youtu.be/..."
                       value="<?= sanitize($editVideo['youtube_url']??'') ?>"
                       oninput="previewURL(this.value)">
                <!-- Preview da thumbnail -->
                <div class="url-preview" id="url-preview">
                    <img id="url-thumb" src="" alt="">
                    <span class="url-preview-info">✅ Vídeo válido! Thumbnail carregada.</span>
                </div>
            </div>

            <div class="field">
                <label>Título do vídeo *</label>
                <input class="fi" type="text" name="title" required
                       placeholder="Ex: Como cadastrar um produto no sistema"
                       value="<?= sanitize($editVideo['title']??'') ?>">
            </div>

            <div class="field">
                <label>Descrição (opcional)</label>
                <textarea class="fi" name="description" rows="2"
                          placeholder="Breve descrição do que o vídeo ensina..."><?= sanitize($editVideo['description']??'') ?></textarea>
            </div>

            <div class="fi-grid2">
                <div class="field">
                    <label>Categoria</label>
                    <input class="fi" type="text" name="category" list="cat-list"
                           placeholder="Ex: Produtos, Clientes, Caixa..."
                           value="<?= sanitize($editVideo['category']??'Geral') ?>">
                    <datalist id="cat-list">
                        <?php foreach($cats as $c): ?><option value="<?= sanitize($c) ?>"><?php endforeach; ?>
                        <option value="Produtos"><option value="Clientes"><option value="Caixa">
                        <option value="Agenda"><option value="Configurações"><option value="Geral">
                    </datalist>
                </div>
                <div class="field">
                    <label>Ordem de exibição</label>
                    <input class="fi" type="number" name="sort_order" min="0" value="<?= (int)($editVideo['sort_order']??0) ?>" placeholder="0">
                    <p style="font-size:.68rem;color:#94a3b8;margin-top:.2rem;">Menor número = aparece primeiro</p>
                </div>
            </div>

            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn-save">
                    <?= $editVideo ? '💾 Salvar alterações' : '➕ Adicionar vídeo' ?>
                </button>
                <?php if($editVideo): ?>
                <a href="?tab=manage" class="btn-cancel">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Lista de vídeos cadastrados -->
<?php if(!empty($allVideos)): ?>
<div class="adm-card">
    <div class="adm-card-hd">
        <h2>📋 Vídeos Cadastrados</h2>
        <span style="font-size:.75rem;color:#94a3b8;"><?= count($allVideos) ?> vídeo(s)</span>
    </div>
    <div style="overflow-x:auto;">
        <table class="adm-table">
            <thead>
                <tr>
                    <th style="width:90px;">Thumb</th>
                    <th>Título</th>
                    <th>Categoria</th>
                    <th style="width:60px;">Ordem</th>
                    <th style="width:60px;">Status</th>
                    <th style="width:90px;">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($allVideos as $v):
                $ytId  = yt_id($v['youtube_url']);
                $thumb = $ytId ? yt_thumb($ytId) : '';
            ?>
            <tr>
                <td>
                    <?php if($thumb): ?>
                    <img src="<?= sanitize($thumb) ?>" class="adm-thumb" alt=""
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="adm-thumb-empty" style="display:none;">🎬</div>
                    <?php else: ?>
                    <div class="adm-thumb-empty">🎬</div>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="vid-name"><?= sanitize(mb_substr($v['title'],0,50)) ?></div>
                    <?php if(!empty($v['description'])): ?>
                    <div class="vid-url"><?= sanitize(mb_substr($v['description'],0,60)) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="display:inline-block;padding:.18rem .55rem;background:#ede9fe;color:#6d28d9;border-radius:20px;font-size:.68rem;font-weight:700;"><?= sanitize($v['category']??'Geral') ?></span>
                </td>
                <td style="text-align:center;color:#94a3b8;font-weight:600;"><?= (int)$v['sort_order'] ?></td>
                <td style="text-align:center;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="toggle_video" value="1">
                        <input type="hidden" name="vid_id" value="<?= (int)$v['id'] ?>">
                        <button type="submit" class="icon-btn" title="<?= $v['active']?'Ocultar':'Exibir' ?>">
                            <span class="<?= $v['active']?'status-on':'status-off' ?>"></span>
                        </button>
                    </form>
                </td>
                <td>
                    <div style="display:flex;gap:.3rem;">
                        <a href="?tab=manage&edit=<?= (int)$v['id'] ?>" class="icon-btn" title="Editar">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remover este vídeo?')">
                            <input type="hidden" name="delete_video" value="1">
                            <input type="hidden" name="vid_id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" class="icon-btn danger" title="Remover">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                            </button>
                        </form>
                        <button onclick="openVideoById(<?= (int)$v['id'] ?>,'<?= htmlspecialchars(yt_id($v['youtube_url'])) ?>','<?= htmlspecialchars(addslashes($v['title'])) ?>','')" class="icon-btn" title="Visualizar">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="tut-empty">
    <div class="ic">🎬</div>
    <h3>Nenhum vídeo cadastrado ainda</h3>
    <p>Use o formulário acima para adicionar o primeiro tutorial.</p>
</div>
<?php endif; ?>

<?php endif; ?>

</div><!-- /.tut-wrap -->

<!-- ═══════════════════════════════════════
     MODAL: Player de Vídeo
═══════════════════════════════════════ -->
<div class="vid-modal" id="vid-modal" onclick="closeVideoModal(event)">
    <div class="vid-modal-inner" onclick="event.stopPropagation()">
        <div class="vid-modal-header">
            <span class="vid-modal-title" id="modal-title">Carregando...</span>
            <button class="vid-modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="vid-iframe-wrap">
            <iframe id="yt-iframe" src="" allowfullscreen allow="autoplay; encrypted-media" loading="lazy"></iframe>
        </div>
        <div class="vid-modal-desc" id="modal-desc" style="display:none;"></div>
    </div>
</div>

<script>
// ── Abre modal com o player YouTube ──
function openVideo(id, ytId, title, desc) {
    if (!ytId) { alert('Vídeo inválido.'); return; }
    document.getElementById('modal-title').textContent = title;
    document.getElementById('yt-iframe').src = 'https://www.youtube.com/embed/' + ytId + '?autoplay=1&rel=0&modestbranding=1';
    const descEl = document.getElementById('modal-desc');
    if (desc) { descEl.textContent = desc; descEl.style.display = 'block'; }
    else { descEl.style.display = 'none'; }
    document.getElementById('vid-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function openVideoById(id, ytId, title, desc) {
    openVideo(id, ytId, title, desc);
}

function closeModal() {
    document.getElementById('vid-modal').classList.remove('open');
    document.getElementById('yt-iframe').src = ''; // para o vídeo
    document.body.style.overflow = '';
}

function closeVideoModal(e) {
    if (e.target === document.getElementById('vid-modal')) closeModal();
}

// Fecha com ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── Preview da URL do YouTube ──
function extractYTId(url) {
    const patterns = [/youtu\.be\/([a-zA-Z0-9_\-]{11})/, /[?&]v=([a-zA-Z0-9_\-]{11})/, /embed\/([a-zA-Z0-9_\-]{11})/];
    for (const p of patterns) { const m = url.match(p); if (m) return m[1]; }
    return null;
}

function previewURL(url) {
    const preview = document.getElementById('url-preview');
    const thumb   = document.getElementById('url-thumb');
    const id      = extractYTId(url);
    if (id) {
        thumb.src = 'https://img.youtube.com/vi/' + id + '/mqdefault.jpg';
        preview.classList.add('show');
    } else {
        preview.classList.remove('show');
    }
}

// Dispara preview se já tiver valor ao carregar (edição)
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('yt-url-input');
    if (input && input.value) previewURL(input.value);
});

<?php if($watchVideo): // Abre direto se URL tem ?watch=
    $wYtId = yt_id($watchVideo['youtube_url']);
?>
document.addEventListener('DOMContentLoaded', () => {
    openVideo(<?= (int)$watchVideo['id'] ?>, '<?= $wYtId ?>', '<?= htmlspecialchars(addslashes($watchVideo['title'])) ?>', '<?= htmlspecialchars(addslashes($watchVideo['description']??'')) ?>');
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>