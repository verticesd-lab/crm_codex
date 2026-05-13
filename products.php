<?php
/**
 * products.php — Gestão de Produtos / Serviços
 * Layout: tabela densa + painel lateral de edição
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$companyId = current_company_id();
if (!$companyId) {
    $row = $pdo->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetch();
    if ($row) { $companyId = (int)$row['id']; $_SESSION['company_id'] = $companyId; }
    else { echo 'Empresa não configurada.'; exit; }
}

/* ─── migração automática — roda no carregamento da página ─────── */
try {
    $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('referencia',$cols))  $pdo->exec("ALTER TABLE products ADD COLUMN referencia VARCHAR(100) DEFAULT NULL");
    if (!in_array('cor',$cols))         $pdo->exec("ALTER TABLE products ADD COLUMN cor VARCHAR(80) DEFAULT NULL");
    if (!in_array('estoque',$cols))     $pdo->exec("ALTER TABLE products ADD COLUMN estoque INT DEFAULT 0");
    if (!in_array('desconto',$cols))    $pdo->exec("ALTER TABLE products ADD COLUMN desconto DECIMAL(5,2) DEFAULT 0");
    if (!in_array('preco_custo',$cols)) $pdo->exec("ALTER TABLE products ADD COLUMN preco_custo DECIMAL(10,2) DEFAULT NULL");
    if (!in_array('imagem2',$cols))     $pdo->exec("ALTER TABLE products ADD COLUMN imagem2 VARCHAR(255) DEFAULT NULL");
    if (!in_array('imagem3',$cols))     $pdo->exec("ALTER TABLE products ADD COLUMN imagem3 VARCHAR(255) DEFAULT NULL");
    if (!in_array('imagem4',$cols))     $pdo->exec("ALTER TABLE products ADD COLUMN imagem4 VARCHAR(255) DEFAULT NULL");

    // Colunas de oferta (flash sale)
    if (!in_array('em_oferta',       $cols)) $pdo->exec("ALTER TABLE products ADD COLUMN em_oferta       TINYINT(1)    NOT NULL DEFAULT 0");
    if (!in_array('preco_oferta',    $cols)) $pdo->exec("ALTER TABLE products ADD COLUMN preco_oferta    DECIMAL(10,2) NULL DEFAULT NULL");
    if (!in_array('preco_original',  $cols)) $pdo->exec("ALTER TABLE products ADD COLUMN preco_original  DECIMAL(10,2) NULL DEFAULT NULL");
    if (!in_array('oferta_estoque',  $cols)) $pdo->exec("ALTER TABLE products ADD COLUMN oferta_estoque  INT           NULL DEFAULT NULL");
    if (!in_array('oferta_validade', $cols)) $pdo->exec("ALTER TABLE products ADD COLUMN oferta_validade DATETIME      NULL DEFAULT NULL");
    if (!in_array('oferta_parcelas', $cols)) $pdo->exec("ALTER TABLE products ADD COLUMN oferta_parcelas TINYINT       NULL DEFAULT 2");
} catch(Throwable $e) {}

/* ─── helpers de variantes ─────────────────────────────────────── */
function sync_variants($pdo, int $pid, string $sizesCsv): void {
    $sizes = array_filter(array_map('trim', explode(',', $sizesCsv)));
    $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id=?');
    $stmt->execute([$pid]);
    $old = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($old) {
        $ph = implode(',', array_fill(0, count($old), '?'));
        $pdo->prepare("DELETE FROM stock_movements WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM stock_balances WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM product_variants WHERE id IN ($ph)")->execute($old);
    }
    foreach ($sizes as $sz) {
        $pdo->prepare('INSERT INTO product_variants (product_id,size,active,created_at,updated_at) VALUES (?,?,1,NOW(),NOW())')->execute([$pid,$sz]);
        $vid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO stock_balances (product_variant_id,location,quantity,updated_at) VALUES (?,?,0,NOW())')->execute([$vid,'loja_fisica']);
    }
}

function parse_price(string $v): float {
    $v = str_replace(['R$',' '], '', $v);
    if (substr_count($v,',') && substr_count($v,'.')) { $v = str_replace('.','',$v); $v = str_replace(',','.',$v); }
    else $v = str_replace(',','.',$v);
    return max(0, (float)preg_replace('/[^0-9.]/','',$v));
}

function ini_size_to_bytes(?string $value): int {
    $value = trim((string)$value);
    if ($value === '') return 0;

    $unit = strtolower(substr($value, -1));
    $bytes = (float)$value;

    switch ($unit) {
        case 'g':
            $bytes *= 1024;
        case 'm':
            $bytes *= 1024;
        case 'k':
            $bytes *= 1024;
            break;
    }

    return (int)round($bytes);
}

function format_bytes_br(int $bytes): string {
    if ($bytes <= 0) return '0 B';

    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = min((int)floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $pow);

    return number_format($value, $pow === 0 ? 0 : 1, ',', '.') . ' ' . $units[$pow];
}

function upload_error_message(string $label, int $error, string $limitText): ?string {
    if ($error === UPLOAD_ERR_OK || $error === UPLOAD_ERR_NO_FILE) return null;

    switch ($error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return $label . ' excede o limite de upload do servidor (' . $limitText . ').';
        case UPLOAD_ERR_PARTIAL:
            return $label . ' nao terminou de ser enviada. Tente novamente.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'O servidor esta sem pasta temporaria para receber a imagem.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'O servidor nao conseguiu gravar a imagem enviada.';
        case UPLOAD_ERR_EXTENSION:
            return 'Uma extensao do servidor bloqueou o envio da imagem.';
        default:
            return 'Falha no upload de ' . $label . '.';
    }
}

/* ─── state ─────────────────────────────────────────────────────── */
$action       = $_GET['action'] ?? 'list';
$q            = trim($_GET['q'] ?? '');
$cat          = trim($_GET['categoria'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));

$requestedPP  = (int)($_GET['per_page'] ?? 30);
$perPage      = in_array($requestedPP, [30, 60, 100]) ? $requestedPP : 30;

$filterAt     = $_GET['ativo'] ?? '';
$ofertaTab    = $_GET['tab'] ?? 'produtos';

$flashSuccess = get_flash('success');
$flashError   = get_flash('error');

$backQs = http_build_query([
    'q'         => $q,
    'categoria' => $cat,
    'page'      => $page,
    'per_page'  => $perPage,
    'ativo'     => $filterAt
]);

/* AÇÕES DE OFERTA via POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['acao_oferta'])) {
    $ao = $_POST['acao_oferta'];

    if ($ao === 'ativar') {
        $id  = (int)$_POST['product_id'];
        $po  = (float)str_replace(',', '.', $_POST['preco_oferta']   ?? '0');
        $por = (float)str_replace(',', '.', $_POST['preco_original'] ?? '0');
        $est = (int)($_POST['oferta_estoque']  ?? 0);
        $val = trim($_POST['oferta_validade']  ?? '') ?: null;
        $par = (int)($_POST['oferta_parcelas'] ?? 2);

        if ($po > 0) {
            $pdo->prepare("UPDATE products SET em_oferta=1,preco_oferta=?,preco_original=?,oferta_estoque=?,oferta_validade=?,oferta_parcelas=? WHERE id=? AND company_id=?")
                ->execute([$po, $por ?: null, $est ?: null, $val, $par, $id, $companyId]);
            flash('success', 'Produto adicionado à oferta!');
        } else {
            flash('error', 'Informe o preço promocional.');
        }
    }

    if ($ao === 'remover') {
        $pdo->prepare("UPDATE products SET em_oferta=0 WHERE id=? AND company_id=?")
            ->execute([(int)$_POST['product_id'], $companyId]);
        flash('success', 'Produto removido da oferta.');
    }

    if ($ao === 'upd_estoque') {
        $pdo->prepare("UPDATE products SET oferta_estoque=? WHERE id=? AND company_id=?")
            ->execute([(int)$_POST['novo_estoque'], (int)$_POST['product_id'], $companyId]);
        flash('success', 'Estoque atualizado.');
    }

    if ($ao === 'limpar_tudo') {
        $pdo->prepare("UPDATE products SET em_oferta=0 WHERE company_id=?")->execute([$companyId]);
        flash('success', 'Todos removidos da oferta.');
    }

    redirect('products.php?tab=ofertas&' . $backQs);
}

/* ACOES DE CATEGORIA via POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['acao_categoria'])) {
    $acaoCategoria = $_POST['acao_categoria'];

    if ($acaoCategoria === 'renomear') {
        $categoriaAtual = (string)($_POST['categoria_atual'] ?? '');
        $categoriaNova  = trim($_POST['categoria_nova'] ?? '');

        if (trim($categoriaAtual) === '' || $categoriaNova === '') {
            flash('error', 'Informe a categoria atual e o novo nome.');
        } elseif ($categoriaAtual === $categoriaNova) {
            flash('error', 'O novo nome precisa ser diferente da categoria atual.');
        } else {
            $updCat = $pdo->prepare('
                UPDATE products
                SET categoria = ?, updated_at = NOW()
                WHERE company_id = ? AND BINARY categoria = ?
            ');
            $updCat->execute([$categoriaNova, $companyId, $categoriaAtual]);
            $updatedRows = (int)$updCat->rowCount();

            if ($updatedRows > 0) {
                flash('success', 'Categoria atualizada em ' . $updatedRows . ' produto(s).');
            } else {
                flash('error', 'Nenhum produto encontrado para essa categoria.');
            }
        }
    }

    redirect('products.php?tab=categorias');
}

$appUploadLimitBytes       = MAX_UPLOAD_SIZE_MB * 1024 * 1024;
$phpUploadLimitBytes       = ini_size_to_bytes((string)ini_get('upload_max_filesize'));
$phpPostLimitBytes         = ini_size_to_bytes((string)ini_get('post_max_size'));
$effectiveUploadLimitBytes = $phpUploadLimitBytes > 0 ? min($appUploadLimitBytes, $phpUploadLimitBytes) : $appUploadLimitBytes;
$postBufferBytes           = 1024 * 1024;
$postImageBudgetBytes      = $phpPostLimitBytes > 0
    ? max(512 * 1024, (int)floor(max(0, $phpPostLimitBytes - $postBufferBytes) / 4))
    : $effectiveUploadLimitBytes;
$clientImageTargetBytes    = max(512 * 1024, min($effectiveUploadLimitBytes, $postImageBudgetBytes));
$effectiveUploadLimitText  = format_bytes_br($effectiveUploadLimitBytes);
$clientImageTargetText     = format_bytes_br($clientImageTargetBytes);

/* ─── DELETE ─────────────────────────────────────────────────────── */
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $old = $pdo->prepare('SELECT id FROM product_variants WHERE product_id=?');
    $old->execute([$id]); $old = $old->fetchAll(PDO::FETCH_COLUMN);
    if ($old) {
        $ph = implode(',', array_fill(0,count($old),'?'));
        $pdo->prepare("DELETE FROM stock_movements WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM stock_balances WHERE product_variant_id IN ($ph)")->execute($old);
        $pdo->prepare("DELETE FROM product_variants WHERE id IN ($ph)")->execute($old);
    }
    $pdo->prepare('DELETE FROM products WHERE id=? AND company_id=?')->execute([$id,$companyId]);
    flash('success','Produto removido.');
    redirect('products.php'.($backQs?'?'.$backQs:''));
}

/* ─── TOGGLE ATIVO ──────────────────────────────── */
if ($action === 'toggle_ativo' && isset($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $cur = $pdo->prepare('SELECT ativo FROM products WHERE id=? AND company_id=?');
    $cur->execute([$id,$companyId]);
    $cur = $cur->fetchColumn();
    $pdo->prepare('UPDATE products SET ativo=?,updated_at=NOW() WHERE id=? AND company_id=?')
        ->execute([$cur ? 0 : 1, $id, $companyId]);
    flash('success', $cur ? 'Produto desativado.' : 'Produto ativado.');
    redirect('products.php'.($backQs?'?'.$backQs:''));
}

/* ─── TOGGLE DESTAQUE ────────────────────────────────────────────── */
if ($action === 'toggle_destaque' && isset($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $cur = $pdo->prepare('SELECT destaque FROM products WHERE id=? AND company_id=?');
    $cur->execute([$id,$companyId]);
    $cur = $cur->fetchColumn();
    $pdo->prepare('UPDATE products SET destaque=?,updated_at=NOW() WHERE id=? AND company_id=?')
        ->execute([$cur ? 0 : 1, $id, $companyId]);
    redirect('products.php'.($backQs?'?'.$backQs:''));
}

/* ─── CREATE / UPDATE ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = (int)($_POST['id'] ?? 0);
    $nome      = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $preco     = parse_price($_POST['preco'] ?? '0');
    $precoCusto= isset($_POST['preco_custo']) ? parse_price($_POST['preco_custo']) : null;
    $catPost   = trim($_POST['categoria'] ?? '');
    $ref       = trim($_POST['referencia'] ?? '');
    $cor       = trim($_POST['cor'] ?? '');
    $estoque   = max(0,(int)($_POST['estoque'] ?? 0));
    $desconto  = max(0,min(100,(float)($_POST['desconto'] ?? 0)));
    $ativo     = isset($_POST['ativo']) ? 1 : 0;
    $destaque  = isset($_POST['destaque']) ? 1 : 0;
    $sizes     = trim($_POST['sizes'] ?? '');
    $oldImage  = trim($_POST['current_image'] ?? '');

    $retQ = http_build_query(array_filter([
        'q'        => trim($_POST['return_q'] ?? ''),
        'categoria'=> trim($_POST['return_cat'] ?? ''),
        'page'     => max(1,(int)($_POST['return_page']??1)),
        'per_page' => $_POST['return_per_page'] ?? 30,
        'ativo'    => $_POST['return_ativo'] ?? '',
    ]));

    if ($nome === '' || $preco <= 0) {
        flash('error','Informe nome e preço válido.');
        redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ);
    }

    $imgPath  = $oldImage;
    $imgPath2 = trim($_POST['current_image2'] ?? '');
    $imgPath3 = trim($_POST['current_image3'] ?? '');
    $imgPath4 = trim($_POST['current_image4'] ?? '');

    $imageLabels = [
        'imagem'  => 'A foto principal',
        'imagem2' => 'A foto 2',
        'imagem3' => 'A foto 3',
        'imagem4' => 'A foto 4',
    ];

    foreach ($imageLabels as $field => $label) {
        $err = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
        $msg = upload_error_message($label, $err, $effectiveUploadLimitText);
        if ($msg !== null) {
            flash('error', $msg . ' Fotos do celular precisam ficar dentro de ' . $effectiveUploadLimitText . ' por arquivo.');
            redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ);
        }
    }

    if (!empty($_FILES['imagem']['name']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $up = upload_image_optimized('imagem','uploads/products');
        if ($up === null) {
            flash('error','A foto principal nao pode ser processada. Use JPG, PNG ou WebP com ate ' . $effectiveUploadLimitText . '.');
            redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ);
        }
        $imgPath = $up;
    }
    if (!empty($_FILES['imagem2']['name']) && $_FILES['imagem2']['error'] === UPLOAD_ERR_OK) {
        $up2 = upload_image_optimized('imagem2','uploads/products');
        if ($up2 === null) {
            flash('error','A foto 2 nao pode ser processada. Use JPG, PNG ou WebP com ate ' . $effectiveUploadLimitText . '.');
            redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ);
        }
        $imgPath2 = $up2;
    }
    if (!empty($_FILES['imagem3']['name']) && $_FILES['imagem3']['error'] === UPLOAD_ERR_OK) {
        $up3 = upload_image_optimized('imagem3','uploads/products');
        if ($up3 === null) {
            flash('error','A foto 3 nao pode ser processada. Use JPG, PNG ou WebP com ate ' . $effectiveUploadLimitText . '.');
            redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ);
        }
        $imgPath3 = $up3;
    }
    if (!empty($_FILES['imagem4']['name']) && $_FILES['imagem4']['error'] === UPLOAD_ERR_OK) {
        $up4 = upload_image_optimized('imagem4','uploads/products');
        if ($up4 === null) {
            flash('error','A foto 4 nao pode ser processada. Use JPG, PNG ou WebP com ate ' . $effectiveUploadLimitText . '.');
            redirect('products.php?action='.($id?'edit':'create').'&id='.$id.'&'.$retQ);
        }
        $imgPath4 = $up4;
    }

    if ($id > 0) {
        $pdo->prepare('UPDATE products SET nome=?,descricao=?,preco=?,preco_custo=?,categoria=?,referencia=?,cor=?,estoque=?,desconto=?,sizes=?,imagem=?,imagem2=?,imagem3=?,imagem4=?,ativo=?,destaque=?,updated_at=NOW() WHERE id=? AND company_id=?')
            ->execute([$nome,$descricao,$preco,$precoCusto,$catPost,$ref,$cor,$estoque,$desconto,$sizes,$imgPath,$imgPath2,$imgPath3,$imgPath4,$ativo,$destaque,$id,$companyId]);
        sync_variants($pdo,$id,$sizes);
        flash('success','Produto atualizado.');
    } else {
        $pdo->prepare('INSERT INTO products (company_id,nome,descricao,preco,preco_custo,categoria,referencia,cor,estoque,desconto,sizes,imagem,imagem2,imagem3,imagem4,ativo,destaque,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')
            ->execute([$companyId,$nome,$descricao,$preco,$precoCusto,$catPost,$ref,$cor,$estoque,$desconto,$sizes,$imgPath,$imgPath2,$imgPath3,$imgPath4,$ativo,$destaque]);
        $newId = (int)$pdo->lastInsertId();
        sync_variants($pdo,$newId,$sizes);
        flash('success','Produto cadastrado com sucesso.');
    }
    redirect('products.php'.($retQ?'?'.$retQ:''));
}

/* ─── PRODUTO PARA EDIÇÃO ───────────────────────────────────────── */
$editingProduct = null;
if (($action === 'edit' || $action === 'create') && isset($_GET['id'])) {
    $s = $pdo->prepare('SELECT * FROM products WHERE id=? AND company_id=?');
    $s->execute([(int)$_GET['id'],$companyId]);
    $editingProduct = $s->fetch() ?: null;
}

/* ─── CATEGORIAS ────────────────────────────────────────────────── */
$cats = $pdo->prepare('SELECT DISTINCT categoria FROM products WHERE company_id=? AND categoria IS NOT NULL AND categoria<>"" ORDER BY categoria');
$cats->execute([$companyId]);
$categories = $cats->fetchAll(PDO::FETCH_COLUMN);

$catStatsStmt = $pdo->prepare('
    SELECT
        categoria,
        COUNT(*) AS total,
        SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos
    FROM products
    WHERE company_id = ? AND categoria IS NOT NULL AND categoria <> ""
    GROUP BY BINARY categoria, categoria
    ORDER BY categoria
');
$catStatsStmt->execute([$companyId]);
$categoryStats = $catStatsStmt->fetchAll();

/* ─── STATS ─────────────────────────────────────────────────────── */
$statsRow = $pdo->prepare('SELECT COUNT(*) total, SUM(ativo) ativos, SUM(destaque) destaques, SUM(CASE WHEN ativo=0 THEN 1 ELSE 0 END) inativos FROM products WHERE company_id=?');
$statsRow->execute([$companyId]);
$stats = $statsRow->fetch();

/* ─── LISTAGEM ──────────────────────────────────────────────────── */
$where  = ['company_id=?'];
$params = [$companyId];
if ($q !== '')       { $where[] = 'nome LIKE ?';        $params[] = '%'.$q.'%'; }
if ($cat !== '')     { $where[] = 'categoria=?';         $params[] = $cat; }
if ($filterAt !== '') { $where[] = 'ativo=?';            $params[] = (int)$filterAt; }
$whereStr = implode(' AND ', $where);

$cntStmt   = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $whereStr");
$cntStmt->execute($params);
$totalRows = (int)$cntStmt->fetchColumn();
$totalPages= max(1,(int)ceil($totalRows/$perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page-1)*$perPage;

$listStmt = $pdo->prepare("SELECT * FROM products WHERE $whereStr ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$products = $listStmt->fetchAll();

/* Dados para a aba de ofertas */
$ofStmt = $pdo->prepare("SELECT * FROM products WHERE company_id=? AND ativo=1 ORDER BY em_oferta DESC, nome ASC");
$ofStmt->execute([$companyId]);
$allProducts     = $ofStmt->fetchAll();
$totalEmOferta   = (int)array_sum(array_column($allProducts, 'em_oferta'));
$totalEstOferta  = (int)array_sum(array_column($allProducts, 'oferta_estoque'));
$defaultValidade = date('Y-m-d\TH:i', strtotime('next saturday 23:59'));

/* ─── RENDER ────────────────────────────────────────────────────── */
$flashSuccess = get_flash('success') ?? $flashSuccess;
$flashError   = get_flash('error')   ?? $flashError;

include __DIR__ . '/views/partials/header.php';
?>

<style>
/* ── Variables ── */
:root { --pr:1rem; }

/* ── Layout ── */
.pr-wrap { display:flex; gap:0; height:calc(100vh - 64px); margin:-1.5rem; overflow:hidden; font-family:'Inter',system-ui,sans-serif; }

/* ── Left panel (list) ── */
.pr-main { flex:1; display:flex; flex-direction:column; overflow:hidden; background:#f8fafc; }

/* ── Topbar ── */
.pr-topbar { padding:.75rem 1.25rem; background:#fff; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; gap:.75rem; flex-wrap:wrap; }
.pr-topbar h1 { font-size:1rem; font-weight:700; color:#0f172a; }
.pr-stats { display:flex; gap:1.25rem; }
.pr-stat { text-align:center; }
.pr-stat-val { font-size:1rem; font-weight:800; color:#6366f1; line-height:1; }
.pr-stat-label { font-size:.6rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }

/* ── Filters bar ── */
.pr-filters { padding:.6rem 1.25rem; background:#fff; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
.pr-search { position:relative; }
.pr-search input { padding:.45rem .75rem .45rem 2.1rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.82rem; outline:none; background:#f8fafc; width:220px; }
.pr-search input:focus { border-color:#6366f1; background:#fff; }
.pr-search svg { position:absolute; left:.65rem; top:50%; transform:translateY(-50%); color:#94a3b8; pointer-events:none; }
.pr-select { padding:.42rem .65rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.8rem; background:#f8fafc; color:#374151; outline:none; cursor:pointer; }
.pr-select:focus { border-color:#6366f1; }
.filter-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.35rem .75rem; border-radius:20px; font-size:.72rem; font-weight:600; border:1.5px solid #e2e8f0; background:#fff; color:#64748b; cursor:pointer; text-decoration:none; transition:all .15s; }
.filter-chip:hover,.filter-chip.active { border-color:#6366f1; background:#f0f9ff; color:#6366f1; }
.filter-chip.active { background:#ede9fe; border-color:#6366f1; color:#6366f1; }
.filter-chip.green.active { background:#dcfce7; border-color:#16a34a; color:#15803d; }
.filter-chip.red.active   { background:#fee2e2; border-color:#dc2626; color:#dc2626; }

/* ── Product table ── */
.pr-table-wrap { flex:1; overflow-y:auto; padding:.75rem 1.25rem 1.25rem; }
.pr-table-wrap::-webkit-scrollbar { width:4px; }
.pr-table-wrap::-webkit-scrollbar-thumb { background:#e2e8f0; border-radius:2px; }

.pr-table { width:100%; border-collapse:collapse; font-size:.82rem; background:#fff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; }
.pr-table thead th { padding:.65rem .85rem; text-align:left; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.pr-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; cursor:pointer; }
.pr-table tbody tr:last-child { border-bottom:none; }
.pr-table tbody tr:hover { background:#f8fafc; }
.pr-table tbody tr.selected { background:#ede9fe; }
.pr-table td { padding:.6rem .85rem; vertical-align:middle; }

/* thumb — AUMENTADO para 52px no desktop */
.prod-thumb { width:52px; height:52px; border-radius:9px; object-fit:cover; background:#f1f5f9; border:1px solid #e2e8f0; flex-shrink:0; }
.prod-thumb-empty { width:52px; height:52px; border-radius:9px; background:#f1f5f9; border:1px dashed #e2e8f0; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:1.1rem; flex-shrink:0; }
.prod-name-cell { display:flex; align-items:center; gap:.6rem; }
.prod-name { font-weight:600; color:#0f172a; }
.prod-ref  { font-size:.68rem; color:#94a3b8; }

/* badges */
.badge { display:inline-flex; align-items:center; gap:.25rem; font-size:.65rem; font-weight:700; padding:.15rem .5rem; border-radius:20px; white-space:nowrap; }
.badge-active   { background:#dcfce7; color:#15803d; }
.badge-inactive { background:#f1f5f9; color:#64748b; }
.badge-destaque { background:#fef9c3; color:#a16207; }
.badge-cat      { background:#ede9fe; color:#6d28d9; }

/* price */
.price-main { font-weight:700; color:#0f172a; white-space:nowrap; }
.price-custo { font-size:.68rem; color:#94a3b8; }
.price-margin { font-size:.68rem; color:#16a34a; font-weight:600; }

/* actions */
.row-actions { display:flex; align-items:center; gap:.3rem; }
.icon-btn { width:26px; height:26px; border-radius:6px; border:1.5px solid #e2e8f0; background:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; text-decoration:none; transition:all .15s; }
.icon-btn:hover { border-color:#6366f1; color:#6366f1; background:#f0f0ff; }
.icon-btn.danger:hover { border-color:#ef4444; color:#ef4444; background:#fef2f2; }

/* ── Right panel (form) ── */
.pr-panel { width:0; min-width:0; transition:width .25s cubic-bezier(.4,0,.2,1), min-width .25s; overflow:hidden; background:#fff; border-left:1px solid #e2e8f0; display:flex; flex-direction:column; }
.pr-panel.open { width:420px; min-width:420px; }

.panel-header { padding:1rem 1.25rem .75rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.panel-header h2 { font-size:.95rem; font-weight:700; color:#0f172a; }
.panel-close { width:28px; height:28px; border-radius:7px; border:1.5px solid #e2e8f0; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:1rem; transition:all .15s; }
.panel-close:hover { border-color:#ef4444; color:#ef4444; }

/* ── Multi-image grid ── */
.img-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
/* Slot é um <label> — clique/toque nativo abre o file picker no iOS/Android */
.img-slot { position:relative; border:2px dashed #e2e8f0; border-radius:10px; aspect-ratio:1; overflow:hidden; background:#f8fafc; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; flex-direction:column; }
.img-slot:hover { border-color:#6366f1; background:#f5f3ff; }
.img-slot.has-img { border-style:solid; border-color:#e2e8f0; }
.img-slot img.slot-preview { width:100%; height:100%; object-fit:cover; display:none; position:absolute; inset:0; z-index:1; }
.img-slot.has-img img.slot-preview { display:block; }
.img-slot-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-top:.35rem; }
.img-slot-icon { font-size:1.4rem; }
/* Input fica FORA do slot, display:none — label for="id" dispara sem JS */
.img-file-input { display:none; }
.img-upload-hint { margin-top:.55rem; font-size:.72rem; line-height:1.45; color:#64748b; }
.img-upload-hint.error { color:#dc2626; font-weight:700; }
.img-slot .img-slot-overlay { display:none; position:absolute; inset:0; background:rgba(0,0,0,.52); align-items:center; justify-content:center; z-index:5; }
.img-slot.has-img:hover .img-slot-overlay,
.img-slot.has-img.show-overlay .img-slot-overlay { display:flex; }
.img-slot-del { background:rgba(239,68,68,.9); color:#fff; border:none; border-radius:7px; padding:.4rem .8rem; font-size:.75rem; font-weight:700; cursor:pointer; z-index:6; }
.img-slot-main-badge { position:absolute; top:.35rem; left:.35rem; background:#6366f1; color:#fff; font-size:.58rem; font-weight:800; padding:.15rem .4rem; border-radius:4px; text-transform:uppercase; letter-spacing:.04em; z-index:4; pointer-events:none; }
.panel-body { flex:1; overflow-y:auto; padding:1rem 1.25rem; }
.panel-body::-webkit-scrollbar { width:3px; }
.panel-body::-webkit-scrollbar-thumb { background:#e2e8f0; }
.panel-footer { padding:.75rem 1.25rem; border-top:1px solid #f1f5f9; display:flex; gap:.5rem; }

/* Form fields */
.field { margin-bottom:.85rem; }
.field label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; margin-bottom:.3rem; }
.fi { width:100%; padding:.5rem .75rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.85rem; background:#f8fafc; color:#0f172a; outline:none; transition:border-color .15s; font-family:inherit; }
.fi:focus { border-color:#6366f1; background:#fff; }
.fi-grid { display:grid; gap:.6rem; }
.fi-grid-2 { grid-template-columns:1fr 1fr; }
.fi-grid-3 { grid-template-columns:1fr 1fr 1fr; }

/* Image preview */
.img-preview-area { border:2px dashed #e2e8f0; border-radius:10px; padding:1rem; text-align:center; cursor:pointer; transition:all .2s; background:#f8fafc; position:relative; }
.img-preview-area:hover { border-color:#6366f1; background:#f5f3ff; }
.img-preview-area img { max-height:120px; max-width:100%; object-fit:contain; border-radius:6px; }
.img-preview-area input { position:absolute; inset:0; opacity:0; cursor:pointer; }

/* Sizes */
.sizes-wrap { display:flex; flex-wrap:wrap; gap:.4rem; }
.sz-chip { display:inline-flex; align-items:center; gap:.25rem; padding:.3rem .65rem; border-radius:20px; border:1.5px solid #e2e8f0; background:#fff; font-size:.75rem; font-weight:600; cursor:pointer; transition:all .15s; user-select:none; }
.sz-chip.on { border-color:#6366f1; background:#ede9fe; color:#4f46e5; }
.sz-chip input { display:none; }

/* Toggle switch */
.tog-wrap { display:flex; align-items:center; gap:.6rem; }
.tog { position:relative; width:38px; height:20px; }
.tog input { opacity:0; width:0; height:0; }
.tog-slider { position:absolute; inset:0; background:#e2e8f0; border-radius:20px; cursor:pointer; transition:.2s; }
.tog-slider::after { content:''; position:absolute; width:14px; height:14px; left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s; }
.tog input:checked + .tog-slider { background:#22c55e; }
.tog input:checked + .tog-slider::after { left:21px; }
.tog-label { font-size:.8rem; color:#374151; font-weight:500; }

/* Btns */
.btn-save { flex:1; padding:.65rem; background:#6366f1; color:#fff; border:none; border-radius:8px; font-size:.875rem; font-weight:700; cursor:pointer; transition:background .15s; }
.btn-save:hover { background:#4f46e5; }
.btn-save.green { background:#16a34a; }
.btn-save.green:hover { background:#15803d; }
.btn-cancel { padding:.65rem 1rem; background:#f1f5f9; color:#374151; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.875rem; font-weight:600; cursor:pointer; transition:all .15s; }
.btn-cancel:hover { background:#e2e8f0; }

/* ── Pagination ── */
.pr-pagination { padding:.65rem 1.25rem; background:#fff; border-top:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem; }
.pag-info { font-size:.75rem; color:#94a3b8; }
.pag-btns { display:flex; gap:.25rem; }
.pag-btn { width:28px; height:28px; border-radius:6px; border:1.5px solid #e2e8f0; background:#fff; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:600; color:#475569; text-decoration:none; transition:all .15s; }
.pag-btn:hover { border-color:#6366f1; color:#6366f1; }
.pag-btn.cur { background:#6366f1; border-color:#6366f1; color:#fff; }
.pag-btn.disabled { opacity:.4; pointer-events:none; }

/* Flash */
.flash-ok  { padding:.65rem 1rem; border-radius:8px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.82rem; margin:.5rem 1.25rem 0; }
.flash-err { padding:.65rem 1rem; border-radius:8px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.82rem; margin:.5rem 1.25rem 0; }

/* ══════════════════════════════════════════════════════════════════
   MOBILE RESPONSIVE — cards com foto visível
   Oculta a tabela e exibe cards com imagem grande e legível
══════════════════════════════════════════════════════════════════ */

/* Desktop: esconde os cards mobile */
.mobile-cards-wrap { display: none; }

@media (max-width: 768px) {

  /* ── Layout ── */
  .pr-wrap {
    flex-direction: column;
    height: auto;
    overflow: visible;
    margin: -.75rem;
  }
  .pr-main { overflow: visible; }

  /* Painel lateral vira bottom sheet no mobile */
  .pr-panel {
    width: 100% !important;
    min-width: 100% !important;
    max-height: 90vh;
    border-left: none;
    border-top: 2px solid #e2e8f0;
    border-radius: 16px 16px 0 0;
  }
  .pr-panel.open {
    width: 100% !important;
    min-width: 100% !important;
  }

  /* Topbar compacto */
  .pr-topbar { padding: .6rem .9rem; gap: .5rem; }
  .pr-topbar h1 { font-size: .88rem; }
  .pr-stats { gap: .75rem; }
  .pr-stat-val { font-size: .82rem; }
  .pr-stat-label { font-size: .55rem; }

  /* Filtros empilhados */
  .pr-filters { flex-direction: column; align-items: stretch; gap: .4rem; padding: .6rem .9rem; }
  .pr-search { width: 100%; }
  .pr-search input { width: 100%; box-sizing: border-box; }
  .pr-select { width: 100%; }

  /* Esconde a tabela no mobile */
  .pr-table-wrap { display: none; }

  /* ── Cards de produto — mostram a foto ── */
  .mobile-cards-wrap {
    display: block;
    padding: .6rem .9rem 1rem;
    overflow-y: auto;
  }

  .mobile-card {
    display: flex;
    align-items: center;
    gap: .75rem;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: .65rem .85rem;
    margin-bottom: .45rem;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s;
  }
  .mobile-card:active { border-color: #6366f1; box-shadow: 0 0 0 3px #ede9fe; }
  .mobile-card.selected { border-color: #6366f1; background: #faf5ff; }

  /* Foto grande e visível no card */
  .mc-photo {
    flex-shrink: 0;
    width: 68px;
    height: 68px;
    border-radius: 10px;
    object-fit: cover;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
  }
  .mc-photo-empty {
    flex-shrink: 0;
    width: 68px;
    height: 68px;
    border-radius: 10px;
    background: #f1f5f9;
    border: 1.5px dashed #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
  }

  /* Conteúdo do card */
  .mc-info { flex: 1; min-width: 0; }
  .mc-name {
    font-size: .88rem;
    font-weight: 700;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .mc-meta { font-size: .7rem; color: #94a3b8; margin-top: .1rem; }
  .mc-price { font-size: .9rem; font-weight: 800; color: #0f172a; margin-top: .25rem; }
  .mc-badges { display: flex; gap: .3rem; flex-wrap: wrap; margin-top: .3rem; }

  /* Botões de ação rápida no card */
  .mc-actions {
    display: flex;
    flex-direction: column;
    gap: .3rem;
    flex-shrink: 0;
  }
  .mc-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1.5px solid #e2e8f0;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    text-decoration: none;
    font-size: .8rem;
  }
  .mc-btn.danger { border-color: #fca5a5; color: #ef4444; }

  /* Paginação compacta */
  .pr-pagination { padding: .65rem .9rem; flex-wrap: wrap; }
  .pag-info { width: 100%; text-align: center; }
  .pag-btns { justify-content: center; width: 100%; }

  /* Botão "Novo Produto" — fixo no canto inferior direito no mobile */
  .mobile-fab {
    display: flex !important;
    position: fixed;
    bottom: 1.25rem;
    right: 1.25rem;
    z-index: 999;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: #6366f1;
    color: #fff;
    border: none;
    font-size: 1.6rem;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 16px rgba(99,102,241,.45);
    cursor: pointer;
  }
}

/* Desktop: esconde o FAB mobile */
.mobile-fab { display: none; }

/* Tabs */
.pr-tabs { display:flex; gap:0; border-bottom:2px solid #e2e8f0; padding:0 1.25rem; background:#fff; }
.pr-tab  { padding:.6rem 1rem; font-size:.8rem; font-weight:700; color:#94a3b8; border-bottom:2px solid transparent; margin-bottom:-2px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:.35rem; transition:color .15s; }
.pr-tab:hover { color:#6366f1; }
.pr-tab.active { color:#6366f1; border-bottom-color:#6366f1; }
.pr-tab .tcnt { background:#ede9fe; color:#6366f1; font-size:.65rem; font-weight:800; padding:.1rem .45rem; border-radius:20px; }
.pr-tab.gold .tcnt  { background:#fef9c3; color:#a16207; }
.pr-tab.gold.active { color:#d97706; border-bottom-color:#d97706; }
.pr-tab.teal .tcnt  { background:#ccfbf1; color:#0f766e; }
.pr-tab.teal.active { color:#0f766e; border-bottom-color:#0f766e; }

/* Aba Ofertas */
.of-wrap  { flex:1; overflow-y:auto; padding-bottom:2rem; }
.of-kpis  { display:flex; gap:.75rem; padding:1rem 1.25rem .5rem; flex-wrap:wrap; }
.of-kpi   { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.75rem 1.1rem; min-width:130px; flex:1; }
.of-kpi .val { font-size:1.6rem; font-weight:800; line-height:1; }
.of-kpi .lbl { font-size:.62rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-top:.2rem; }
.of-bar   { display:flex; align-items:center; justify-content:space-between; padding:.5rem 1.25rem; flex-wrap:wrap; gap:.5rem; }
.of-view  { display:inline-flex; align-items:center; gap:.35rem; font-size:.75rem; font-weight:700; color:#16a34a; border:1px solid #86efac; background:#f0fdf4; padding:.4rem .9rem; border-radius:8px; text-decoration:none; }
.of-view:hover { background:#dcfce7; }
.of-clr   { font-size:.72rem; font-weight:700; color:#ef4444; border:1px solid #fca5a5; background:#fff; padding:.4rem .9rem; border-radius:8px; cursor:pointer; }
.of-clr:hover { background:#fef2f2; }

.of-table { width:calc(100% - 2.5rem); margin:0 1.25rem; border-collapse:collapse; font-size:.82rem; background:#fff; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; }
.of-table thead th { padding:.65rem .85rem; text-align:left; font-size:.63rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; background:#fafafa; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.of-table tbody tr { border-bottom:1px solid #f8fafc; transition:background .1s; }
.of-table tbody tr:last-child { border-bottom:none; }
.of-table tbody tr:hover { background:#fafcff; }
.of-table td { padding:.65rem .85rem; vertical-align:middle; }
.of-on  { background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:2px 9px; border-radius:20px; font-size:.65rem; font-weight:800; white-space:nowrap; }
.of-off { background:#f1f5f9; color:#94a3b8; border:1px solid #e2e8f0; padding:2px 9px; border-radius:20px; font-size:.65rem; font-weight:700; white-space:nowrap; }
.of-pp  { font-weight:700; color:#6366f1; white-space:nowrap; }
.of-po  { font-size:.7rem; color:#94a3b8; text-decoration:line-through; white-space:nowrap; }

.of-row  { display:flex; gap:.35rem; align-items:center; flex-wrap:wrap; }
.of-inp  { background:#f8fafc; border:1.5px solid #e2e8f0; color:#0f172a; padding:5px 8px; border-radius:7px; font-size:.78rem; }
.of-inp:focus { outline:none; border-color:#6366f1; }
.of-w90  { width:90px; }
.of-w60  { width:60px; }
.of-w55  { width:55px; }
.of-w140 { width:140px; }
.of-btn  { padding:5px 12px; border-radius:7px; font-size:.75rem; font-weight:700; cursor:pointer; border:none; white-space:nowrap; }
.of-add  { background:#6366f1; color:#fff; }
.of-add:hover { background:#4f46e5; }
.of-rem  { background:#fff; color:#ef4444; border:1px solid #fca5a5; }
.of-rem:hover { background:#fef2f2; }
.of-upd  { background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }
.of-upd:hover { background:#bae6fd; }

/* Aba Categorias */
.cat-wrap { flex:1; overflow-y:auto; padding:1rem 1.25rem 2rem; }
.cat-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:.9rem; flex-wrap:wrap; }
.cat-head h2 { font-size:1rem; font-weight:800; color:#0f172a; margin:0 0 .2rem; }
.cat-head p { font-size:.78rem; color:#64748b; margin:0; }
.cat-note { background:#ecfeff; border:1px solid #a5f3fc; color:#155e75; border-radius:10px; padding:.65rem .85rem; font-size:.76rem; font-weight:600; }
.cat-table { width:100%; border-collapse:collapse; font-size:.82rem; background:#fff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; }
.cat-table thead th { padding:.7rem .85rem; text-align:left; font-size:.64rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.cat-table tbody tr { border-bottom:1px solid #f1f5f9; }
.cat-table tbody tr:last-child { border-bottom:none; }
.cat-table tbody tr:hover { background:#f8fafc; }
.cat-table td { padding:.7rem .85rem; vertical-align:middle; }
.cat-name { display:inline-flex; align-items:center; max-width:260px; padding:.22rem .55rem; border-radius:999px; background:#ede9fe; color:#6d28d9; font-size:.76rem; font-weight:800; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.cat-count { font-size:.76rem; color:#475569; font-weight:700; white-space:nowrap; }
.cat-count span { color:#94a3b8; font-weight:600; }
.cat-form { display:flex; align-items:center; gap:.45rem; flex-wrap:wrap; }
.cat-input { min-width:220px; flex:1; padding:.46rem .65rem; border:1.5px solid #e2e8f0; border-radius:8px; font-size:.8rem; outline:none; background:#f8fafc; color:#0f172a; }
.cat-input:focus { border-color:#0f766e; background:#fff; }
.cat-btn { padding:.48rem .85rem; border:none; border-radius:8px; background:#0f766e; color:#fff; font-size:.76rem; font-weight:800; cursor:pointer; white-space:nowrap; }
.cat-btn:hover { background:#115e59; }
.cat-empty { background:#fff; border:1px dashed #cbd5e1; border-radius:12px; padding:2rem; text-align:center; color:#94a3b8; font-size:.85rem; }

@media (max-width:768px) {
  .of-table { margin:0 .5rem; width:calc(100% - 1rem); font-size:.74rem; }
  .of-kpis  { padding:.75rem .9rem .4rem; }
  .of-bar   { padding:.4rem .9rem; }
  .of-inp.of-w90  { width:74px; }
  .of-inp.of-w140 { width:116px; }
  .cat-wrap { padding:.75rem .9rem 1.5rem; }
  .cat-table { display:block; overflow-x:auto; }
  .cat-input { min-width:180px; }
}
</style>

<?php if ($flashSuccess): ?>
  <div class="flash-ok">✅ <?= sanitize($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="flash-err">⚠️ <?= sanitize($flashError) ?></div>
<?php endif; ?>

<div class="pr-wrap" id="pr-wrap">

  <!-- ══════════════ LEFT — LIST ══════════════ -->
  <div class="pr-main">

    <!-- Topbar -->
    <div class="pr-topbar">
      <div style="display:flex;align-items:center;gap:1rem;">
        <h1>Produtos / Serviços</h1>
        <div class="pr-stats">
          <div class="pr-stat"><div class="pr-stat-val"><?= (int)$stats['total'] ?></div><div class="pr-stat-label">Total</div></div>
          <div class="pr-stat"><div class="pr-stat-val" style="color:#22c55e;"><?= (int)$stats['ativos'] ?></div><div class="pr-stat-label">Ativos</div></div>
          <div class="pr-stat"><div class="pr-stat-val" style="color:#94a3b8;"><?= (int)$stats['inativos'] ?></div><div class="pr-stat-label">Inativos</div></div>
          <div class="pr-stat"><div class="pr-stat-val" style="color:#f59e0b;"><?= (int)$stats['destaques'] ?></div><div class="pr-stat-label">Destaque</div></div>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;">
        <a href="products_imports.php" style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;font-size:.78rem;font-weight:600;color:#475569;text-decoration:none;transition:all .15s;"
           onmouseover="this.style.borderColor='#6366f1';this.style.color='#6366f1'" onmouseout="this.style.borderColor='#e2e8f0';this.style.color='#475569'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Importar em Lote
        </a>
        <button onclick="openPanel(null)" style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:8px;background:#6366f1;color:#fff;border:none;font-size:.78rem;font-weight:700;cursor:pointer;transition:background .15s;"
                onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='#6366f1'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Novo Produto
        </button>
      </div>
    </div>

    <!-- TABS Produtos / Ofertas -->
    <div class="pr-tabs">
      <a href="?tab=produtos&<?= $backQs ?>" class="pr-tab <?= $ofertaTab==='produtos'?'active':'' ?>">
        📦 Produtos <span class="tcnt"><?= (int)$stats['total'] ?></span>
      </a>
      <a href="?tab=ofertas&<?= $backQs ?>" class="pr-tab gold <?= $ofertaTab==='ofertas'?'active':'' ?>">
        ⚡ Ofertas <span class="tcnt"><?= $totalEmOferta ?></span>
      </a>
      <a href="?tab=categorias&<?= $backQs ?>" class="pr-tab teal <?= $ofertaTab==='categorias'?'active':'' ?>">
        Categorias <span class="tcnt"><?= count($categoryStats) ?></span>
      </a>
    </div>

    <!-- Filters -->
    <form method="GET" id="filter-form">
      <div class="pr-filters">
        <input type="hidden" name="tab" value="<?= sanitize($ofertaTab) ?>">
        <div class="pr-search">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar produto…" onchange="this.form.submit()">
        </div>

        <select name="categoria" class="pr-select" onchange="this.form.submit()">
          <option value="">Todas as categorias</option>
          <?php foreach($categories as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $cat===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>

        <?php
        $atUrl = fn($v) => '?'.http_build_query(array_filter(
            ['tab'=>$ofertaTab,'q'=>$q,'categoria'=>$cat,'per_page'=>$perPage,'ativo'=>$v],
            static fn($item) => $item !== '' && $item !== null
        ));
        ?>
        <a href="<?= $atUrl('') ?>" class="filter-chip <?= $filterAt==='' ?'active':'' ?>">Todos</a>
        <a href="<?= $atUrl('1') ?>" class="filter-chip green <?= $filterAt==='1'?'active':'' ?>">
          <span style="width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block;"></span>Ativos
        </a>
        <a href="<?= $atUrl('0') ?>" class="filter-chip red <?= $filterAt==='0'?'active':'' ?>">
          <span style="width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block;"></span>Inativos
        </a>

        <select name="per_page" class="pr-select" onchange="this.form.submit()" style="margin-left:auto;">
          <?php foreach([30,60,100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?>/página</option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="page" value="1">
      </div>
    </form>

    <?php if ($ofertaTab === 'ofertas'): ?>
    <!-- ABA OFERTAS -->
    <div class="of-wrap">

      <div class="of-kpis">
        <div class="of-kpi">
          <div class="val" style="color:#6366f1;"><?= $totalEmOferta ?></div>
          <div class="lbl">Em oferta</div>
        </div>
        <div class="of-kpi">
          <div class="val" style="color:#22c55e;"><?= $totalEstOferta ?: '—' ?></div>
          <div class="lbl">Pares disponíveis</div>
        </div>
        <div class="of-kpi">
          <div class="val"><?= count($allProducts) ?></div>
          <div class="lbl">Produtos ativos</div>
        </div>
      </div>

      <div class="of-bar">
        <span style="font-size:.75rem;color:#94a3b8;">Ative com 1 clique — preencha preço promocional e clique ⚡ Ativar</span>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
          <a href="<?= BASE_URL ?>/ofertas.php?empresa=<?= urlencode($_SESSION['company_slug'] ?? '') ?>"
             target="_blank" rel="noopener" class="of-view">
            👁 Ver página pública ↗
          </a>
          <form method="POST" onsubmit="return confirm('Remover TODOS da oferta?')">
            <input type="hidden" name="acao_oferta" value="limpar_tudo">
            <button type="submit" class="of-clr">🧹 Remover todos</button>
          </form>
        </div>
      </div>

      <table class="of-table">
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>Produto</th>
            <th>Status</th>
            <th>Preço Loja</th>
            <th>Preço Oferta / Original</th>
            <th>Estoque Oferta</th>
            <th>Validade</th>
            <th>Ação</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($allProducts)): ?>
          <tr><td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8;">Nenhum produto ativo cadastrado.</td></tr>
        <?php endif; ?>

        <?php foreach ($allProducts as $p):
          $pPreco   = (float)($p['preco'] ?? 0);
          $desconto = 0;
          if (!empty($p['preco_original']) && (float)$p['preco_original'] > 0 && !empty($p['preco_oferta'])) {
              $desconto = round((1 - (float)$p['preco_oferta'] / (float)$p['preco_original']) * 100);
          }
        ?>
        <tr>
          <td>
            <?php if (!empty($p['imagem'])): ?>
              <img src="<?= sanitize(image_url($p['imagem'])) ?>"
                   style="width:40px;height:40px;border-radius:8px;object-fit:cover;border:1px solid #e2e8f0;" alt="">
            <?php else: ?>
              <div style="width:40px;height:40px;border-radius:8px;background:#f1f5f9;border:1px dashed #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:.9rem;">📷</div>
            <?php endif; ?>
          </td>

          <td>
            <div style="font-weight:600;color:#0f172a;font-size:.82rem;"><?= sanitize($p['nome']) ?></div>
            <?php if (!empty($p['categoria'])): ?>
              <div style="font-size:.68rem;color:#94a3b8;"><?= sanitize($p['categoria']) ?></div>
            <?php endif; ?>
          </td>

          <td>
            <?php if ($p['em_oferta']): ?>
              <span class="of-on">● EM OFERTA</span>
              <?php if ($desconto > 0): ?>
                <div style="font-size:.64rem;color:#d97706;margin-top:.15rem;">-<?= $desconto ?>% desc.</div>
              <?php endif; ?>
            <?php else: ?>
              <span class="of-off">○ Fora</span>
            <?php endif; ?>
          </td>

          <td style="font-size:.82rem;color:#475569;white-space:nowrap;"><?= format_currency($pPreco) ?></td>

          <td>
            <?php if ($p['em_oferta'] && $p['preco_oferta']): ?>
              <div class="of-pp"><?= format_currency($p['preco_oferta']) ?></div>
              <?php if ($p['preco_original']): ?>
                <div class="of-po"><?= format_currency($p['preco_original']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#cbd5e1;font-size:.76rem;">—</span>
            <?php endif; ?>
          </td>

          <td>
            <?php if ($p['em_oferta']): ?>
              <form method="POST" class="of-row">
                <input type="hidden" name="acao_oferta" value="upd_estoque">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <input type="number" name="novo_estoque" class="of-inp of-w60"
                       value="<?= (int)($p['oferta_estoque'] ?? 0) ?>" min="0">
                <button type="submit" class="of-btn of-upd">✓</button>
              </form>
            <?php else: ?>
              <span style="color:#cbd5e1;">—</span>
            <?php endif; ?>
          </td>

          <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap;">
            <?= !empty($p['oferta_validade']) ? date('d/m H:i', strtotime($p['oferta_validade'])) : '—' ?>
          </td>

          <td>
            <?php if ($p['em_oferta']): ?>
              <form method="POST">
                <input type="hidden" name="acao_oferta" value="remover">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <button type="submit" class="of-btn of-rem">✕ Remover</button>
              </form>
            <?php else: ?>
              <form method="POST" class="of-row">
                <input type="hidden" name="acao_oferta" value="ativar">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <input type="number" name="preco_oferta" class="of-inp of-w90"
                       step="0.01" placeholder="R$ promo" required>
                <input type="number" name="preco_original" class="of-inp of-w90"
                       step="0.01" placeholder="R$ original"
                       value="<?= $pPreco > 0 ? $pPreco : '' ?>">
                <input type="number" name="oferta_estoque" class="of-inp of-w60"
                       placeholder="Estoque" min="0">
                <select name="oferta_parcelas" class="of-inp of-w55">
                  <option value="1">1x</option>
                  <option value="2" selected>2x</option>
                  <option value="3">3x</option>
                </select>
                <input type="datetime-local" name="oferta_validade" class="of-inp of-w140"
                       value="<?= $defaultValidade ?>">
                <button type="submit" class="of-btn of-add">⚡ Ativar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php elseif ($ofertaTab === 'categorias'): ?>
    <!-- ABA CATEGORIAS -->
    <div class="cat-wrap">
      <div class="cat-head">
        <div>
          <h2>Organizar categorias</h2>
          <p>Renomeie uma categoria para atualizar todos os produtos vinculados a ela.</p>
        </div>
        <div class="cat-note">Se o novo nome ja existir, os produtos serao agrupados nele.</div>
      </div>

      <?php if (empty($categoryStats)): ?>
        <div class="cat-empty">Nenhuma categoria cadastrada ainda.</div>
      <?php else: ?>
      <table class="cat-table">
        <thead>
          <tr>
            <th>Categoria atual</th>
            <th>Produtos</th>
            <th>Novo nome</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($categoryStats as $catRow):
          $catName = (string)$catRow['categoria'];
          $catTotal = (int)$catRow['total'];
          $catActive = (int)$catRow['ativos'];
        ?>
          <tr>
            <td><span class="cat-name"><?= sanitize($catName) ?></span></td>
            <td>
              <div class="cat-count">
                <?= $catTotal ?> produto(s)
                <span><?= $catActive ?> ativo(s)</span>
              </div>
            </td>
            <td>
              <form method="POST" class="cat-form" onsubmit="return confirm('Atualizar esta categoria em todos os produtos vinculados?')">
                <input type="hidden" name="acao_categoria" value="renomear">
                <input type="hidden" name="categoria_atual" value="<?= sanitize($catName) ?>">
                <input type="text" name="categoria_nova" class="cat-input" value="<?= sanitize($catName) ?>" placeholder="Ex: Kits" required>
                <button type="submit" class="cat-btn">Renomear</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php else: /* tab = produtos — tabela normal abaixo */ ?>

    <!-- ══ DESKTOP TABLE ══ -->
    <div class="pr-table-wrap">
      <?php if (empty($products)): ?>
        <div style="text-align:center;padding:3rem;color:#94a3b8;">
          <div style="font-size:2.5rem;margin-bottom:.5rem;">📦</div>
          <p style="font-size:.875rem;">Nenhum produto encontrado.</p>
          <button onclick="openPanel(null)" style="margin-top:1rem;padding:.6rem 1.2rem;background:#6366f1;color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;">+ Cadastrar produto</button>
        </div>
      <?php else: ?>
      <table class="pr-table">
        <thead>
          <tr>
            <th style="width:60px;"></th>
            <th>Produto</th>
            <th>Categoria</th>
            <th>Preço Venda</th>
            <th>Custo / Margem</th>
            <th>Estoque</th>
            <th>Status</th>
            <th style="width:100px;">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($products as $p):
            $preco  = (float)$p['preco'];
            $custo  = isset($p['preco_custo']) ? (float)$p['preco_custo'] : 0;
            $margem = ($custo > 0 && $preco > 0) ? round(($preco-$custo)/$preco*100) : null;
            $estoque= isset($p['estoque']) ? (int)$p['estoque'] : '—';
          ?>
          <tr onclick="openPanel(<?= (int)$p['id'] ?>)" id="row-<?= (int)$p['id'] ?>">
            <td onclick="event.stopPropagation()">
              <?php if(!empty($p['imagem'])): ?>
                <img src="<?= sanitize(image_url($p['imagem'])) ?>" class="prod-thumb" alt="">
              <?php else: ?>
                <div class="prod-thumb-empty">📷</div>
              <?php endif; ?>
            </td>
            <td>
              <div class="prod-name-cell">
                <div>
                  <div class="prod-name"><?= sanitize($p['nome']) ?></div>
                  <?php if(!empty($p['referencia'])): ?>
                    <div class="prod-ref">REF: <?= sanitize($p['referencia']) ?><?= !empty($p['cor']) ? ' · '.$p['cor'] : '' ?></div>
                  <?php elseif(!empty($p['cor'])): ?>
                    <div class="prod-ref"><?= sanitize($p['cor']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?php if(!empty($p['categoria'])): ?>
                <span class="badge badge-cat"><?= sanitize($p['categoria']) ?></span>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="price-main">R$ <?= number_format($preco,2,',','.') ?></div>
              <?php if(isset($p['desconto']) && $p['desconto']>0): ?>
                <div class="prod-ref" style="color:#f59e0b;">↓ <?= (int)$p['desconto'] ?>% desc.</div>
              <?php endif; ?>
            </td>
            <td>
              <?php if($custo > 0): ?>
                <div class="price-custo">Custo: R$ <?= number_format($custo,2,',','.') ?></div>
                <?php if($margem !== null): ?>
                  <div class="price-margin">Margem: <?= $margem ?>%</div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if(is_numeric($estoque)): ?>
                <span style="font-weight:700;color:<?= $estoque==0?'#ef4444':($estoque<5?'#f59e0b':'#0f172a') ?>;"><?= $estoque ?></span>
                <?php if($estoque==0): ?>
                  <div style="font-size:.65rem;color:#ef4444;">Sem estoque</div>
                <?php elseif($estoque<5): ?>
                  <div style="font-size:.65rem;color:#f59e0b;">Estoque baixo</div>
                <?php endif; ?>
              <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:.2rem;">
                <span class="badge <?= $p['ativo']?'badge-active':'badge-inactive' ?>"><?= $p['ativo']?'Ativo':'Inativo' ?></span>
                <?php if($p['destaque']): ?><span class="badge badge-destaque">⭐ Destaque</span><?php endif; ?>
              </div>
            </td>
            <td onclick="event.stopPropagation()">
              <div class="row-actions">
                <a href="?action=toggle_ativo&id=<?= (int)$p['id'] ?>&<?= $backQs ?>" class="icon-btn" title="<?= $p['ativo']?'Desativar':'Ativar' ?>">
                  <?= $p['ativo']
                    ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17.94 17.94A10 10 0 0112 20C6.48 20 2 15.52 2 10c0-1.48.33-2.88.94-4.12M9.9 4.24A9.12 9.12 0 0112 4c5.52 0 10 4.48 10 10 0 .74-.08 1.46-.24 2.15M12 12h.01"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
                    : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
                  ?>
                </a>
                <a href="product_stock.php?id=<?= (int)$p['id'] ?>" class="icon-btn" title="Estoque">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </a>
                <a href="?action=delete&id=<?= (int)$p['id'] ?>&<?= $backQs ?>" class="icon-btn danger" title="Remover"
                   onclick="return confirm('Remover <?= addslashes(htmlspecialchars($p['nome'])) ?>?')">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- ══ MOBILE CARDS — visíveis apenas em telas ≤ 768px ══ -->
    <div class="mobile-cards-wrap">
      <?php if (empty($products)): ?>
        <div style="text-align:center;padding:3rem;color:#94a3b8;">
          <div style="font-size:2.5rem;margin-bottom:.5rem;">📦</div>
          <p style="font-size:.875rem;">Nenhum produto encontrado.</p>
        </div>
      <?php else: ?>
        <?php foreach($products as $p):
          $preco  = (float)$p['preco'];
          $custo  = isset($p['preco_custo']) ? (float)$p['preco_custo'] : 0;
          $margem = ($custo > 0 && $preco > 0) ? round(($preco-$custo)/$preco*100) : null;
          $estoque= isset($p['estoque']) ? (int)$p['estoque'] : null;
        ?>
        <div class="mobile-card" id="mcard-<?= (int)$p['id'] ?>" onclick="openPanel(<?= (int)$p['id'] ?>)">

          <!-- Foto do produto (grande e bem visível) -->
          <?php if(!empty($p['imagem'])): ?>
            <img src="<?= sanitize(image_url($p['imagem'])) ?>" class="mc-photo" alt="<?= sanitize($p['nome']) ?>">
          <?php else: ?>
            <div class="mc-photo-empty">📷</div>
          <?php endif; ?>

          <!-- Informações -->
          <div class="mc-info">
            <div class="mc-name"><?= sanitize($p['nome']) ?></div>
            <?php if(!empty($p['referencia']) || !empty($p['cor'])): ?>
              <div class="mc-meta">
                <?= !empty($p['referencia']) ? 'REF: '.sanitize($p['referencia']) : '' ?>
                <?= !empty($p['cor']) ? ' · '.sanitize($p['cor']) : '' ?>
              </div>
            <?php endif; ?>
            <div class="mc-price">R$ <?= number_format($preco,2,',','.') ?>
              <?php if($margem !== null): ?>
                <span style="font-size:.72rem;font-weight:500;color:#16a34a;margin-left:.3rem;">Margem <?= $margem ?>%</span>
              <?php endif; ?>
            </div>
            <div class="mc-badges">
              <?php if(!empty($p['categoria'])): ?>
                <span class="badge badge-cat"><?= sanitize($p['categoria']) ?></span>
              <?php endif; ?>
              <span class="badge <?= $p['ativo']?'badge-active':'badge-inactive' ?>"><?= $p['ativo']?'Ativo':'Inativo' ?></span>
              <?php if($p['destaque']): ?>
                <span class="badge badge-destaque">⭐</span>
              <?php endif; ?>
              <?php if($estoque !== null): ?>
                <span class="badge" style="background:<?= $estoque==0?'#fee2e2':($estoque<5?'#fef9c3':'#f1f5f9') ?>;color:<?= $estoque==0?'#dc2626':($estoque<5?'#a16207':'#475569') ?>;">
                  📦 <?= $estoque ?>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Ações rápidas (param propagação de clique) -->
          <div class="mc-actions" onclick="event.stopPropagation()">
            <a href="?action=toggle_ativo&id=<?= (int)$p['id'] ?>&<?= $backQs ?>" class="mc-btn" title="<?= $p['ativo']?'Desativar':'Ativar' ?>">
              <?= $p['ativo'] ? '🚫' : '✅' ?>
            </a>
            <a href="product_stock.php?id=<?= (int)$p['id'] ?>" class="mc-btn" title="Estoque">📦</a>
            <a href="?action=delete&id=<?= (int)$p['id'] ?>&<?= $backQs ?>" class="mc-btn danger" title="Remover"
               onclick="return confirm('Remover <?= addslashes(htmlspecialchars($p['nome'])) ?>?')">🗑️</a>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if($totalPages > 1): ?>
    <div class="pr-pagination">
      <span class="pag-info"><?= $offset+1 ?>–<?= min($offset+$perPage,$totalRows) ?> de <?= $totalRows ?> produtos</span>
      <div class="pag-btns">
        <?php
        $pgUrl = fn($p) => '?'.http_build_query(array_filter(
            ['tab'=>$ofertaTab,'q'=>$q,'categoria'=>$cat,'per_page'=>$perPage,'ativo'=>$filterAt,'page'=>$p],
            static fn($item) => $item !== '' && $item !== null
        ));
        for($pg=1;$pg<=$totalPages;$pg++):
          if($totalPages > 7 && abs($pg-$page)>2 && $pg!==1 && $pg!==$totalPages){ if($pg===2||$pg===$totalPages-1) echo '<span style="padding:0 .25rem;color:#94a3b8;">…</span>'; continue; }
        ?>
          <a href="<?= $pgUrl($pg) ?>" class="pag-btn <?= $pg===$page?'cur':'' ?>"><?= $pg ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; /* fim if ofertas */ ?>
  </div>

  <!-- ══════════════ RIGHT — FORM PANEL ══════════════ -->
  <div class="pr-panel" id="pr-panel">
    <div class="panel-header">
      <h2 id="panel-title">Novo Produto</h2>
      <button class="panel-close" onclick="closePanel()">✕</button>
    </div>
    <div class="panel-body">
      <form method="POST" enctype="multipart/form-data" id="product-form">
        <input type="hidden" name="id" id="f-id" value="0">
        <input type="hidden" name="return_q" value="<?= htmlspecialchars($q) ?>">
        <input type="hidden" name="return_cat" value="<?= htmlspecialchars($cat) ?>">
        <input type="hidden" name="return_page" value="<?= $page ?>">
        <input type="hidden" name="return_per_page" value="<?= $perPage ?>">
        <input type="hidden" name="return_ativo" value="<?= htmlspecialchars($filterAt) ?>">

        <!-- Imagens (até 4 fotos) -->
        <div class="field">
          <label>Fotos do Produto <span style="font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0;">(até 4 · clique para adicionar)</span></label>

          <!-- Inputs FORA dos slots — display:none ativado via label for="id" (funciona iOS/Android) -->
          <input type="file" name="imagem"  id="f-imagem"  class="img-file-input" accept="image/*" onchange="previewSlot(this,1)">
          <input type="file" name="imagem2" id="f-imagem2" class="img-file-input" accept="image/*" onchange="previewSlot(this,2)">
          <input type="file" name="imagem3" id="f-imagem3" class="img-file-input" accept="image/*" onchange="previewSlot(this,3)">
          <input type="file" name="imagem4" id="f-imagem4" class="img-file-input" accept="image/*" onchange="previewSlot(this,4)">
          <input type="hidden" name="current_image"  id="f-img"  value="">
          <input type="hidden" name="current_image2" id="f-img2" value="">
          <input type="hidden" name="current_image3" id="f-img3" value="">
          <input type="hidden" name="current_image4" id="f-img4" value="">

          <div class="img-grid">

            <!-- Slot 1 — Principal: label for="f-imagem" abre o picker nativamente -->
            <label class="img-slot" id="slot-1" for="f-imagem">
              <span class="img-slot-main-badge">Principal</span>
              <img id="img-preview-1" class="slot-preview" src="" alt="">
              <span class="img-slot-icon">📷</span>
              <span class="img-slot-label">Foto 1</span>
              <div class="img-slot-overlay">
                <button type="button" class="img-slot-del" onclick="clearSlot(event,1)">🗑 Remover</button>
              </div>
            </label>

            <!-- Slot 2 -->
            <label class="img-slot" id="slot-2" for="f-imagem2">
              <img id="img-preview-2" class="slot-preview" src="" alt="">
              <span class="img-slot-icon">📷</span>
              <span class="img-slot-label">Foto 2</span>
              <div class="img-slot-overlay">
                <button type="button" class="img-slot-del" onclick="clearSlot(event,2)">🗑 Remover</button>
              </div>
            </label>

            <!-- Slot 3 -->
            <label class="img-slot" id="slot-3" for="f-imagem3">
              <img id="img-preview-3" class="slot-preview" src="" alt="">
              <span class="img-slot-icon">📷</span>
              <span class="img-slot-label">Foto 3</span>
              <div class="img-slot-overlay">
                <button type="button" class="img-slot-del" onclick="clearSlot(event,3)">🗑 Remover</button>
              </div>
            </label>

            <!-- Slot 4 -->
            <label class="img-slot" id="slot-4" for="f-imagem4">
              <img id="img-preview-4" class="slot-preview" src="" alt="">
              <span class="img-slot-icon">📷</span>
              <span class="img-slot-label">Foto 4</span>
              <div class="img-slot-overlay">
                <button type="button" class="img-slot-del" onclick="clearSlot(event,4)">🗑 Remover</button>
              </div>
            </label>

          </div>

          <div id="img-upload-hint" class="img-upload-hint">
            Fotos do celular sao otimizadas automaticamente antes do envio. Limite atual por foto: <?= sanitize($clientImageTargetText) ?>.
          </div>
        </div>

        <!-- Nome -->
        <div class="field">
          <label>Nome *</label>
          <input class="fi" type="text" name="nome" id="f-nome" required placeholder="Ex: Camiseta Nike Dri-Fit">
        </div>

        <!-- Ref + Cor -->
        <div class="field fi-grid fi-grid-2">
          <div>
            <label>Referência / SKU</label>
            <input class="fi" type="text" name="referencia" id="f-ref" placeholder="REF-001">
          </div>
          <div>
            <label>Cor</label>
            <input class="fi" type="text" name="cor" id="f-cor" placeholder="Preto, Azul...">
          </div>
        </div>

        <!-- Preços -->
        <div class="field fi-grid fi-grid-2">
          <div>
            <label>Preço de Custo R$</label>
            <input class="fi" type="text" name="preco_custo" id="f-custo" placeholder="0,00" oninput="calcMargem()">
          </div>
          <div>
            <label>Preço de Venda R$ *</label>
            <input class="fi" type="text" name="preco" id="f-preco" placeholder="0,00" required oninput="calcMargem()">
          </div>
        </div>
        <div id="margem-preview" style="margin-top:-.5rem;margin-bottom:.75rem;font-size:.75rem;color:#16a34a;font-weight:600;display:none;"></div>

        <!-- Estoque + Desconto -->
        <div class="field fi-grid fi-grid-2">
          <div>
            <label>Qtd em Estoque</label>
            <input class="fi" type="number" name="estoque" id="f-estoque" min="0" value="0">
          </div>
          <div>
            <label>Desconto %</label>
            <input class="fi" type="number" name="desconto" id="f-desconto" min="0" max="100" value="0">
          </div>
        </div>

        <!-- Categoria -->
        <div class="field">
          <label>Categoria</label>
          <input class="fi" type="text" name="categoria" id="f-cat" list="cat-list" placeholder="Tênis, Camisetas, Serviços...">
          <datalist id="cat-list">
            <?php foreach($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
          </datalist>
        </div>

        <!-- Descrição -->
        <div class="field">
          <label>Descrição</label>
          <textarea class="fi" name="descricao" id="f-desc" rows="3" placeholder="Detalhes do produto..."></textarea>
        </div>

        <!-- Tamanhos -->
        <div class="field">
          <label>Tamanhos Disponíveis</label>
          <div style="margin-bottom:.5rem;">
            <select id="sz-preset" class="fi" style="font-size:.78rem;" onchange="renderSizes(this.value)">
              <option value="">— Selecionar tipo —</option>
              <option value="roupa">Roupa (P / M / G / GG / XGG)</option>
              <option value="calcado">Calçado (37 → 45)</option>
              <option value="infantil">Infantil (2 → 16)</option>
            </select>
          </div>
          <div class="sizes-wrap" id="sz-wrap"></div>
          <input type="hidden" name="sizes" id="f-sizes">
        </div>

        <!-- Toggles -->
        <div class="field" style="display:flex;gap:1.5rem;">
          <div class="tog-wrap">
            <label class="tog"><input type="checkbox" name="ativo" id="f-ativo" value="1"><span class="tog-slider"></span></label>
            <span class="tog-label">Ativo na loja</span>
          </div>
          <div class="tog-wrap">
            <label class="tog"><input type="checkbox" name="destaque" id="f-destaque" value="1"><span class="tog-slider"></span></label>
            <span class="tog-label">Destaque</span>
          </div>
        </div>
      </form>
    </div>
    <div class="panel-footer">
      <button type="button" class="btn-cancel" onclick="closePanel()">Cancelar</button>
      <button type="submit" class="btn-save green" id="btn-save-product" form="product-form">💾 Salvar Produto</button>
    </div>
  </div>

</div>

<!-- FAB mobile — botão flutuante "Novo Produto" -->
<button class="mobile-fab" onclick="openPanel(null)" title="Novo Produto">＋</button>

<!-- Products data for panel population -->
<script>
const PRODUCTS_DATA = <?= json_encode(array_column($products, null, 'id')) ?>;
const BASE_URL = '<?= rtrim((string)BASE_URL,'/') ?>';
const PRODUCT_IMAGE_TARGET_BYTES = <?= (int)$clientImageTargetBytes ?>;
const PRODUCT_IMAGE_TARGET_TEXT = <?= json_encode($clientImageTargetText) ?>;
const PRODUCT_IMAGE_MAX_WIDTH = <?= (int)MAX_IMAGE_WIDTH ?>;
const PRODUCT_IMAGE_MAX_HEIGHT = <?= (int)MAX_IMAGE_HEIGHT ?>;
const PRODUCT_ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const pendingImageJobs = new Set();

// ── Panel ──
function openPanel(id) {
  const panel = document.getElementById('pr-panel');
  panel.classList.add('open');
  resetUploadHint();
  setSaveButtonBusy(false, '');
  document.getElementById('product-form').dataset.autoSubmitting = '0';

  // Scroll to panel on mobile
  if (window.innerWidth <= 768) {
    setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
  }

  if (id) {
    const p = PRODUCTS_DATA[id];
    if (!p) return;
    document.getElementById('panel-title').textContent = 'Editar Produto';
    document.getElementById('f-id').value         = p.id;
    document.getElementById('f-nome').value        = p.nome || '';
    document.getElementById('f-ref').value         = p.referencia || '';
    document.getElementById('f-cor').value         = p.cor || '';
    document.getElementById('f-custo').value       = p.preco_custo ? fmt(p.preco_custo) : '';
    document.getElementById('f-preco').value       = fmt(p.preco);
    document.getElementById('f-estoque').value     = p.estoque || 0;
    document.getElementById('f-desconto').value    = p.desconto || 0;
    document.getElementById('f-cat').value         = p.categoria || '';
    document.getElementById('f-desc').value        = p.descricao || '';
    document.getElementById('f-ativo').checked     = parseInt(p.ativo) === 1;
    document.getElementById('f-destaque').checked  = parseInt(p.destaque) === 1;
    document.getElementById('f-img').value         = p.imagem || '';
    document.getElementById('f-sizes').value       = p.sizes || '';

    // Carrega as 4 imagens
    loadSlot(1, p.imagem  || '');
    loadSlot(2, p.imagem2 || '');
    loadSlot(3, p.imagem3 || '');
    loadSlot(4, p.imagem4 || '');

    // Sizes
    autoDetectSizes(p.sizes || '');
    calcMargem();

    // Highlight row desktop
    document.querySelectorAll('.pr-table tbody tr').forEach(r => r.classList.remove('selected'));
    const row = document.getElementById('row-'+id);
    if (row) { row.classList.add('selected'); row.scrollIntoView({block:'nearest'}); }

    // Highlight card mobile
    document.querySelectorAll('.mobile-card').forEach(r => r.classList.remove('selected'));
    const card = document.getElementById('mcard-'+id);
    if (card) card.classList.add('selected');

  } else {
    // New product
    document.getElementById('panel-title').textContent = 'Novo Produto';
    document.getElementById('product-form').reset();
    document.getElementById('f-id').value = '0';
    loadSlot(1,''); loadSlot(2,''); loadSlot(3,''); loadSlot(4,'');
    document.getElementById('sz-wrap').innerHTML = '';
    document.getElementById('f-sizes').value = '';
    document.getElementById('margem-preview').style.display = 'none';
    document.querySelectorAll('.pr-table tbody tr, .mobile-card').forEach(r => r.classList.remove('selected'));
  }
}

function closePanel() {
  document.getElementById('pr-panel').classList.remove('open');
  document.querySelectorAll('.pr-table tbody tr, .mobile-card').forEach(r => r.classList.remove('selected'));
}

// ── Multi-image slots ──
function setUploadHint(message, isError = false) {
  const hint = document.getElementById('img-upload-hint');
  if (!hint) return;
  hint.textContent = message;
  hint.classList.toggle('error', !!isError);
}

function resetUploadHint() {
  setUploadHint('Fotos do celular sao otimizadas automaticamente antes do envio. Limite atual por foto: ' + PRODUCT_IMAGE_TARGET_TEXT + '.');
}

function setSaveButtonBusy(isBusy, label) {
  const btn = document.getElementById('btn-save-product');
  if (!btn) return;
  if (!btn.dataset.defaultLabel) btn.dataset.defaultLabel = btn.innerHTML;
  btn.disabled = isBusy;
  btn.style.opacity = isBusy ? '.82' : '';
  btn.style.cursor = isBusy ? 'wait' : '';
  btn.innerHTML = isBusy ? label : btn.dataset.defaultLabel;
}

function attachImageJob(promise) {
  pendingImageJobs.add(promise);
  setSaveButtonBusy(true, 'Otimizando fotos...');
  promise.finally(() => {
    pendingImageJobs.delete(promise);
    const form = document.getElementById('product-form');
    if (!pendingImageJobs.size && (!form || form.dataset.autoSubmitting !== '1')) {
      setSaveButtonBusy(false, '');
    }
  });
  return promise;
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = e => resolve(e.target.result);
    reader.onerror = () => reject(new Error('Nao foi possivel gerar o preview da imagem.'));
    reader.readAsDataURL(file);
  });
}

function replaceInputFile(input, file) {
  if (typeof DataTransfer === 'undefined') {
    throw new Error('Seu navegador nao permite otimizar essa foto automaticamente. Tente escolher uma imagem menor.');
  }
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
}

function fitSize(width, height, maxWidth, maxHeight) {
  const ratio = Math.min(maxWidth / width, maxHeight / height, 1);
  return {
    width: Math.max(1, Math.round(width * ratio)),
    height: Math.max(1, Math.round(height * ratio)),
  };
}

function canvasToBlob(canvas, type, quality) {
  return new Promise((resolve, reject) => {
    canvas.toBlob(blob => {
      if (blob) resolve(blob);
      else reject(new Error('Nao foi possivel preparar a foto para envio.'));
    }, type, quality);
  });
}

async function loadImageSource(file) {
  if (window.createImageBitmap) {
    const bitmap = await createImageBitmap(file);
    return {
      source: bitmap,
      width: bitmap.width,
      height: bitmap.height,
      cleanup: () => { if (bitmap.close) bitmap.close(); },
    };
  }

  const objectUrl = URL.createObjectURL(file);
  try {
    const img = await new Promise((resolve, reject) => {
      const el = new Image();
      el.onload = () => resolve(el);
      el.onerror = () => reject(new Error('Nao foi possivel ler a foto selecionada.'));
      el.src = objectUrl;
    });
    return {
      source: img,
      width: img.naturalWidth || img.width,
      height: img.naturalHeight || img.height,
      cleanup: () => URL.revokeObjectURL(objectUrl),
    };
  } catch (err) {
    URL.revokeObjectURL(objectUrl);
    throw err;
  }
}

async function prepareImageForUpload(file) {
  const fileName = String(file.name || 'foto').toLowerCase();
  const fileType = String(file.type || '').toLowerCase();
  const looksLikeImage = /\.(jpe?g|png|webp|heic|heif)$/i.test(fileName);

  if (/\.hei(c|f)$/i.test(fileName) || fileType === 'image/heic' || fileType === 'image/heif') {
    throw new Error('Fotos em HEIC/HEIF nao sao aceitas aqui. No iPhone, use JPG ou Mais Compativel antes de enviar.');
  }

  if (!fileType.startsWith('image/') && !looksLikeImage) {
    throw new Error('Selecione um arquivo de imagem valido.');
  }

  let asset = null;
  try {
    asset = await loadImageSource(file);
    const needsResize = asset.width > PRODUCT_IMAGE_MAX_WIDTH || asset.height > PRODUCT_IMAGE_MAX_HEIGHT;
    const needsConvert = !PRODUCT_ALLOWED_TYPES.includes(fileType);
    const needsCompress = file.size > PRODUCT_IMAGE_TARGET_BYTES;

    if (!needsResize && !needsConvert && !needsCompress) {
      return file;
    }

    const initial = fitSize(asset.width, asset.height, PRODUCT_IMAGE_MAX_WIDTH, PRODUCT_IMAGE_MAX_HEIGHT);
    const canvas = document.createElement('canvas');
    let width = initial.width;
    let height = initial.height;
    let quality = 0.88;
    let blob = null;

    while (true) {
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d', { alpha: false });
      if (!ctx) throw new Error('Nao foi possivel preparar a foto para envio.');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, width, height);
      ctx.drawImage(asset.source, 0, 0, width, height);

      blob = await canvasToBlob(canvas, 'image/jpeg', quality);
      if (blob.size <= PRODUCT_IMAGE_TARGET_BYTES) break;

      if (quality > 0.58) {
        quality = Math.max(0.58, quality - 0.08);
        continue;
      }

      if (Math.max(width, height) <= 960) break;

      width = Math.max(1, Math.round(width * 0.85));
      height = Math.max(1, Math.round(height * 0.85));
    }

    if (!blob || blob.size > PRODUCT_IMAGE_TARGET_BYTES) {
      throw new Error('A foto ainda ficou acima do limite de ' + PRODUCT_IMAGE_TARGET_TEXT + '. Tente uma foto mais leve.');
    }

    const cleanName = fileName.replace(/\.[^.]+$/, '') || 'foto';
    return new File([blob], cleanName + '.jpg', {
      type: 'image/jpeg',
      lastModified: Date.now(),
    });
  } finally {
    if (asset && typeof asset.cleanup === 'function') asset.cleanup();
  }
}

function previewSlot(input, n) {
  if (!input.files || !input.files[0]) return;

  const task = (async () => {
    resetUploadHint();
    const originalFile = input.files[0];
    const preparedFile = await prepareImageForUpload(originalFile);
    if (preparedFile !== originalFile) {
      replaceInputFile(input, preparedFile);
    }
    const previewSrc = await fileToDataUrl(input.files[0]);
    setSlotImg(n, previewSrc);
    if (preparedFile !== originalFile) {
      setUploadHint('Foto otimizada com sucesso para envio pelo celular. Limite atual por foto: ' + PRODUCT_IMAGE_TARGET_TEXT + '.');
    }
  })().catch(err => {
    clearSlot({ stopPropagation() {}, preventDefault() {} }, n);
    setUploadHint(err && err.message ? err.message : 'Falha ao preparar a foto para envio.', true);
  });

  attachImageJob(task);
}

function setSlotImg(n, src) {
  const slot = document.getElementById('slot-' + n);
  const img  = document.getElementById('img-preview-' + n);
  if (!slot || !img) return;
  img.src = src;
  slot.classList.add('has-img');
  const icon  = slot.querySelector('.img-slot-icon');
  const label = slot.querySelector('.img-slot-label');
  if (icon)  icon.style.display = 'none';
  if (label) label.style.display = 'none';
  // Mobile: toque longo (600ms) mostra botão Remover
  enableMobileOverlay(slot);
}

function enableMobileOverlay(slot) {
  if (slot._mobSet) return;
  slot._mobSet = true;
  let t;
  slot.addEventListener('touchstart', () => { t = setTimeout(() => slot.classList.add('show-overlay'), 600); }, {passive:true});
  slot.addEventListener('touchend',   () => clearTimeout(t));
  slot.addEventListener('touchmove',  () => clearTimeout(t), {passive:true});
  // Toque fora remove o overlay
  document.addEventListener('touchstart', e => {
    if (!slot.contains(e.target)) slot.classList.remove('show-overlay');
  }, {passive:true});
  // Quando slot já tem imagem: bloqueia o label de abrir o file picker novamente
  // (usuário precisa remover primeiro via botão Remover)
  slot.addEventListener('click', e => {
    if (slot.classList.contains('has-img')) {
      e.preventDefault();
      slot.classList.add('show-overlay');
    }
  });
}

function clearSlot(e, n) {
  e.stopPropagation();
  e.preventDefault();
  const slot   = document.getElementById('slot-' + n);
  const img    = document.getElementById('img-preview-' + n);
  const hidId  = n === 1 ? 'f-img' : 'f-img' + n;
  const fileId = n === 1 ? 'f-imagem' : 'f-imagem' + n;
  if (!slot || !img) return;
  img.src = '';
  slot.classList.remove('has-img', 'show-overlay');
  const icon  = slot.querySelector('.img-slot-icon');
  const lbl   = slot.querySelector('.img-slot-label');
  if (icon) icon.style.display = '';
  if (lbl)  lbl.style.display  = '';
  const hid = document.getElementById(hidId);
  if (hid) hid.value = '';
  // Recria o input para limpar o arquivo (cross-browser)
  const fi = document.getElementById(fileId);
  if (fi) {
    const nf = document.createElement('input');
    nf.type     = 'file';
    nf.name     = fi.name;
    nf.id       = fi.id;
    nf.className = fi.className;
    nf.accept   = 'image/*';
    nf.setAttribute('onchange', `previewSlot(this,${n})`);
    fi.parentNode.replaceChild(nf, fi);
  }
}

function loadSlot(n, url) {
  const slot  = document.getElementById('slot-' + n);
  const img   = document.getElementById('img-preview-' + n);
  const hidId = n === 1 ? 'f-img' : 'f-img' + n;
  const hid   = document.getElementById(hidId);
  if (!slot || !img) return;
  if (url) {
    setSlotImg(n, BASE_URL + '/' + url);
    if (hid) hid.value = url;
  } else {
    img.src = '';
    slot.classList.remove('has-img', 'show-overlay');
    const icon  = slot.querySelector('.img-slot-icon');
    const label = slot.querySelector('.img-slot-label');
    if (icon)  icon.style.display = '';
    if (label) label.style.display = '';
    if (hid) hid.value = '';
  }
}


// ── Margem ──
function fmt(v) { return parseFloat(v||0).toFixed(2).replace('.',','); }
function parsePrice(s) {
  s = String(s).replace(/[^0-9,\.]/g,'');
  if (s.includes(',') && !s.includes('.')) s = s.replace(',','.');
  else if (s.includes(',') && s.includes('.')) { s = s.replace('.',''); s = s.replace(',','.'); }
  return parseFloat(s) || 0;
}
function calcMargem() {
  const c = parsePrice(document.getElementById('f-custo').value);
  const v = parsePrice(document.getElementById('f-preco').value);
  const el = document.getElementById('margem-preview');
  if (c > 0 && v > 0) {
    const m = ((v-c)/v*100).toFixed(1);
    const markup = ((v-c)/c*100).toFixed(1);
    el.textContent = `Margem: ${m}%  ·  Markup: ${markup}%`;
    el.style.display = 'block';
    el.style.color = m >= 30 ? '#16a34a' : m >= 15 ? '#d97706' : '#dc2626';
  } else { el.style.display = 'none'; }
}

// ── Sizes ──
const SIZE_PRESETS = {
  roupa:    ['P','M','G','GG','XGG'],
  calcado:  ['37','38','39','40','41','42','43','44','45'],
  infantil: ['2','4','6','8','10','12','14','16'],
};

function renderSizes(preset, checked=[]) {
  const wrap = document.getElementById('sz-wrap');
  wrap.innerHTML = '';
  const sizes = SIZE_PRESETS[preset] || [];
  sizes.forEach(sz => {
    const label = document.createElement('label');
    label.className = 'sz-chip' + (checked.includes(sz)?' on':'');
    label.innerHTML = `<input type="checkbox" value="${sz}" ${checked.includes(sz)?'checked':''}>${sz}`;
    label.querySelector('input').addEventListener('change', function() {
      label.classList.toggle('on', this.checked);
      updateSizesHidden();
    });
    wrap.appendChild(label);
  });
  updateSizesHidden();
}

function updateSizesHidden() {
  const checked = Array.from(document.querySelectorAll('#sz-wrap input:checked')).map(i=>i.value);
  document.getElementById('f-sizes').value = checked.join(',');
}

function autoDetectSizes(sizesStr) {
  if (!sizesStr) { document.getElementById('sz-wrap').innerHTML = ''; return; }
  const arr = sizesStr.split(',').map(s=>s.trim()).filter(Boolean);
  let preset = '';
  if (arr.some(v => ['P','M','G','GG','XGG'].includes(v))) preset = 'roupa';
  else if (arr.some(v => parseInt(v) >= 37 && parseInt(v) <= 45)) preset = 'calcado';
  else if (arr.some(v => parseInt(v) >= 2 && parseInt(v) <= 16)) preset = 'infantil';
  if (preset) {
    document.getElementById('sz-preset').value = preset;
    renderSizes(preset, arr);
  } else {
    document.getElementById('sz-wrap').innerHTML = '';
  }
}

// ── Close on Escape ──
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

document.getElementById('product-form').addEventListener('submit', async function(e) {
  if (this.dataset.autoSubmitting === '1') {
    setSaveButtonBusy(true, 'Salvando...');
    return;
  }

  if (!pendingImageJobs.size) {
    setSaveButtonBusy(true, 'Salvando...');
    return;
  }

  e.preventDefault();
  setUploadHint('Aguardando a otimizacao das fotos antes de salvar...', false);
  setSaveButtonBusy(true, 'Otimizando fotos...');
  await Promise.allSettled(Array.from(pendingImageJobs));
  this.dataset.autoSubmitting = '1';
  setSaveButtonBusy(true, 'Salvando...');
  if (typeof this.requestSubmit === 'function') this.requestSubmit();
  else this.submit();
});

resetUploadHint();

// ── Auto-open if URL has action=edit or action=create ──
<?php if ($action === 'edit' && $editingProduct): ?>
  document.addEventListener('DOMContentLoaded', () => openPanel(<?= (int)$editingProduct['id'] ?>));
<?php elseif ($action === 'create'): ?>
  document.addEventListener('DOMContentLoaded', () => openPanel(null));
<?php endif; ?>
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
