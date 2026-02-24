<?php
/**
 * products_imports.php
 * Cadastro Inteligente — Importação de mercadoria via CSV/NF
 *
 * FLUXO:
 *   1. Upload do arquivo (CSV da NF / planilha do fornecedor)
 *   2. Processamento → gera itens com nome, qtd, cor, tamanho, preço custo
 *   3. Revisão linha a linha: preço venda, desconto, categoria, foto URL
 *   4. Aprovação individual ou em massa
 *   5. Publicação → insere em `products` (ativo=0 por padrão)
 *   6. Ativação direta na tela de revisão
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

/* ================================================================
   HELPERS
   ================================================================ */

function ni_price($v): ?float {
    if ($v === null || $v === '') return null;
    $s = preg_replace('/[^0-9,\.]/', '', str_replace(['R$',' '], '', trim((string)$v)));
    if (substr_count($s,',') === 1 && substr_count($s,'.') === 0) $s = str_replace(',','.',$s);
    elseif (substr_count($s,',') >= 1 && substr_count($s,'.') >= 1) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
    $f = (float)$s;
    return ($f > 0) ? $f : null;
}

function ni_int($v): int {
    return max(1, (int)preg_replace('/[^0-9]/', '', (string)$v));
}

function ni_norm($s): string {
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

function ni_hdr($x): string {
    $x = preg_replace('/^\xEF\xBB\xBF/', '', trim(mb_strtolower((string)$x)));
    $from = ['á','à','ã','â','é','ê','í','ó','ô','õ','ú','ü','ç'];
    $to   = ['a','a','a','a','e','e','i','o','o','o','u','u','c'];
    return str_replace($from, $to, preg_replace('/\s+/', ' ', $x));
}

function ni_detect_delim(string $path): string {
    $s = @file_get_contents($path, false, null, 0, 2048) ?: '';
    $best = ';'; $bc = -1;
    foreach ([',', ';', "\t", '|'] as $d) {
        $c = substr_count($s, $d);
        if ($c > $bc) { $bc = $c; $best = $d; }
    }
    return $best;
}

/**
 * Mapeia colunas do CSV para campos internos.
 * Aceita tanto NF de fornecedor quanto planilha simples.
 */
function ni_map_cols(array $header): array {
    $h = array_map('ni_hdr', $header);
    $pick = function(array $aliases) use ($h): ?int {
        foreach ($h as $i => $col) {
            foreach ($aliases as $a) {
                if (str_contains($col, ni_hdr($a))) return $i;
            }
        }
        return null;
    };
    return [
        'nome'       => $pick(['nome','produto','descricao do produto','descricao','item','titulo','title','description']),
        'referencia' => $pick(['ref','referencia','codigo','cod','sku','art','artigo']),
        'preco_custo'=> $pick(['custo','preco custo','valor custo','preco compra','valor compra','preco unit','preco unitario','valor unit','vl unit']),
        'preco_venda'=> $pick(['venda','preco venda','valor venda','preco','preco sugerido']),
        'quantidade' => $pick(['quantidade','qtd','qtde','qty','estoque','saldo']),
        'categoria'  => $pick(['categoria','grupo','secao','departamento','category','familia']),
        'cor'        => $pick(['cor','color','couleur']),
        'tamanho'    => $pick(['tamanho','tam','size','grade','numeracao']),
        'descricao'  => $pick(['obs','observacao','detalhe','detalhes','note','notes','complemento']),
    ];
}

function ni_url(string $qs = ''): string {
    $b = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    return $b . '/products_imports.php' . ($qs ? '?'.$qs : '');
}

function ni_ensure_dir(string $d): void {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}

/* ================================================================
   ACTIONS — POST
   ================================================================ */

// ── Upload ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_upload'])) {
    $f   = $_FILES['file'] ?? null;
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK || empty($f['name'])) {
        flash('error', 'Selecione um arquivo válido.');
        redirect(ni_url('action=create'));
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv','xlsx'], true)) {
        flash('error', 'Formato não suportado. Use CSV ou XLSX.');
        redirect(ni_url('action=create'));
    }

    $dir = __DIR__ . '/uploads/imports';
    ni_ensure_dir($dir);
    $fname = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME))
           . '_' . date('Ymd_His') . '.' . $ext;
    $dest  = $dir . '/' . $fname;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        flash('error', 'Falha ao salvar arquivo no servidor.');
        redirect(ni_url('action=create'));
    }

    $pdo->prepare('INSERT INTO product_imports (company_id,original_filename,stored_path,file_ext,status) VALUES (?,?,?,?,"uploaded")')
        ->execute([$companyId, $f['name'], 'uploads/imports/'.$fname, $ext]);
    $id = (int)$pdo->lastInsertId();

    flash('success', 'Arquivo enviado! Clique em PROCESSAR para extrair os produtos.');
    redirect(ni_url('action=view&id='.$id));
}

// ── Processar ──
if (($_GET['action'] ?? '') === 'process' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $imp = $pdo->prepare('SELECT * FROM product_imports WHERE id=? AND company_id=?');
    $imp->execute([$importId, $companyId]);
    $imp = $imp->fetch();
    if (!$imp) { flash('error','Importação não encontrada.'); redirect(ni_url()); }

    $filePath = __DIR__ . '/' . $imp['stored_path'];
    $ext = strtolower($imp['file_ext']);

    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM product_import_items WHERE import_id=? AND company_id=?')
            ->execute([$importId, $companyId]);

        $total = 0;

        if ($ext === 'csv') {
            if (!file_exists($filePath)) throw new Exception('Arquivo não encontrado no servidor.');
            $delim = ni_detect_delim($filePath);
            $fh    = fopen($filePath, 'r');
            if (!$fh) throw new Exception('Não foi possível abrir o CSV.');

            // Detecta encoding e converte para UTF-8
            $firstLine = fgets($fh);
            rewind($fh);
            if (mb_detect_encoding($firstLine, 'UTF-8', true) === false) {
                // Tenta converter de ISO-8859-1
                $content = file_get_contents($filePath);
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
                $tmpFile = tempnam(sys_get_temp_dir(), 'ni_') . '.csv';
                file_put_contents($tmpFile, $content);
                fclose($fh);
                $fh = fopen($tmpFile, 'r');
            }

            $header = fgetcsv($fh, 0, $delim);
            if (!$header) { fclose($fh); throw new Exception('CSV vazio ou inválido.'); }
            $map = ni_map_cols($header);
            if ($map['nome'] === null) $map['nome'] = 0; // fallback: primeira coluna

            $ins = $pdo->prepare('
                INSERT INTO product_import_items
                    (import_id,company_id,row_number,
                     raw_nome,raw_preco,raw_categoria,raw_descricao,
                     final_nome,final_preco,final_categoria,final_descricao,
                     referencia,preco_custo,quantidade,cor,tamanho,
                     status,error_message)
                VALUES
                    (:import_id,:company_id,:row_num,
                     :raw_nome,:raw_preco,:raw_cat,:raw_desc,
                     :nome,:preco_venda,:cat,:desc,
                     :ref,:custo,:qty,:cor,:tam,
                     "draft",NULL)
            ');

            $rowNum = 1;
            while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                $rowNum++;
                if (count($row) === 1 && trim((string)$row[0]) === '') continue;

                $rawNome = isset($map['nome']) ? ($row[$map['nome']] ?? '') : ($row[0] ?? '');
                $nome    = ni_norm($rawNome);
                if ($nome === '') continue;

                $rawPrecoV = isset($map['preco_venda'])  && $map['preco_venda'] !== null  ? ($row[$map['preco_venda']]  ?? '') : '';
                $rawPrecoC = isset($map['preco_custo'])  && $map['preco_custo'] !== null  ? ($row[$map['preco_custo']]  ?? '') : '';
                $rawCat    = isset($map['categoria'])    && $map['categoria']   !== null  ? ($row[$map['categoria']]    ?? '') : '';
                $rawDesc   = isset($map['descricao'])    && $map['descricao']   !== null  ? ($row[$map['descricao']]    ?? '') : '';
                $rawRef    = isset($map['referencia'])   && $map['referencia']  !== null  ? ($row[$map['referencia']]   ?? '') : '';
                $rawQty    = isset($map['quantidade'])   && $map['quantidade']  !== null  ? ($row[$map['quantidade']]   ?? '1') : '1';
                $rawCor    = isset($map['cor'])          && $map['cor']         !== null  ? ($row[$map['cor']]          ?? '') : '';
                $rawTam    = isset($map['tamanho'])      && $map['tamanho']     !== null  ? ($row[$map['tamanho']]      ?? '') : '';

                $precoVenda = ni_price($rawPrecoV) ?: ni_price($rawPrecoC);
                $precoCusto = ni_price($rawPrecoC);

                $ins->execute([
                    ':import_id'  => $importId,
                    ':company_id' => $companyId,
                    ':row_num'    => $rowNum,
                    ':raw_nome'   => $rawNome,
                    ':raw_preco'  => $rawPrecoV ?: $rawPrecoC,
                    ':raw_cat'    => $rawCat,
                    ':raw_desc'   => $rawDesc,
                    ':nome'       => $nome,
                    ':preco_venda'=> $precoVenda,
                    ':cat'        => ni_norm($rawCat),
                    ':desc'       => ni_norm($rawDesc),
                    ':ref'        => ni_norm($rawRef),
                    ':custo'      => $precoCusto,
                    ':qty'        => ni_int($rawQty),
                    ':cor'        => ni_norm($rawCor),
                    ':tam'        => ni_norm($rawTam),
                ]);
                $total++;
            }
            fclose($fh);
        }

        // XLSX — instrução clara
        if ($ext === 'xlsx') {
            throw new Exception('XLSX: exporte a planilha para CSV (Arquivo → Salvar como → CSV UTF-8) e reimporte. Suporte nativo a XLSX chegará em breve.');
        }

        $pdo->prepare('UPDATE product_imports SET status="processed",total_rows=? WHERE id=? AND company_id=?')
            ->execute([$total, $importId, $companyId]);
        $pdo->commit();

        flash('success', "Processado! {$total} produtos extraídos. Revise e precifique antes de publicar.");
        redirect(ni_url('action=view&id='.$importId));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('UPDATE product_imports SET status="failed" WHERE id=? AND company_id=?')
            ->execute([$importId, $companyId]);
        flash('error', 'Falha: '.$e->getMessage());
        redirect(ni_url('action=view&id='.$importId));
    }
}

// ── Salvar item inline ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $itemId  = (int)($_POST['item_id'] ?? 0);
    $status  = isset($_POST['approved']) ? 'approved' : 'draft';
    if (isset($_POST['activate'])) $status = 'approved';

    $pdo->prepare('
        UPDATE product_import_items SET
            final_nome=?, final_preco=?, final_categoria=?, final_descricao=?,
            referencia=?, preco_custo=?, quantidade=?, cor=?, tamanho=?,
            foto_url=?, desconto=?,
            status=?, updated_at=NOW()
        WHERE id=? AND company_id=?
    ')->execute([
        ni_norm($_POST['final_nome']   ?? ''),
        ni_price($_POST['final_preco'] ?? null),
        ni_norm($_POST['final_categoria'] ?? ''),
        ni_norm($_POST['final_descricao'] ?? ''),
        ni_norm($_POST['referencia']   ?? ''),
        ni_price($_POST['preco_custo'] ?? null),
        ni_int($_POST['quantidade']    ?? 1),
        ni_norm($_POST['cor']          ?? ''),
        ni_norm($_POST['tamanho']      ?? ''),
        trim($_POST['foto_url']        ?? ''),
        (float)($_POST['desconto']     ?? 0),
        $status,
        $itemId, $companyId,
    ]);

    flash('success', 'Produto salvo.');
    redirect($_POST['back'] ?? ni_url());
}

// ── Aprovar todos ──
if (($_GET['action'] ?? '') === 'approve_all' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $pdo->prepare('UPDATE product_import_items SET status="approved" WHERE import_id=? AND company_id=? AND status IN ("draft","error")')
        ->execute([$importId, $companyId]);
    flash('success', 'Todos os itens aprovados.');
    redirect(ni_url('action=view&id='.$importId));
}

// ── Publicar aprovados → products ──
if (($_GET['action'] ?? '') === 'publish' && isset($_GET['id'])) {
    $importId  = (int)$_GET['id'];
    $ativar    = isset($_GET['ativo']) ? 1 : 0; // ?ativo=1 publica ativo
    $items     = $pdo->prepare('SELECT * FROM product_import_items WHERE import_id=? AND company_id=? AND status="approved"');
    $items->execute([$importId, $companyId]);
    $items     = $items->fetchAll();

    if (!$items) { flash('error','Nenhum item aprovado.'); redirect(ni_url('action=view&id='.$importId)); }

    $ok = $err = 0;
    foreach ($items as $it) {
        $nome  = trim((string)$it['final_nome']);
        $preco = (float)($it['final_preco'] ?? 0);
        if ($nome === '' || $preco <= 0) {
            $pdo->prepare('UPDATE product_import_items SET status="error",error_message="Nome vazio ou preço inválido" WHERE id=? AND company_id=?')
                ->execute([(int)$it['id'], $companyId]);
            $err++; continue;
        }

        // Verifica duplicata por referência ou nome
        $dup = null;
        if (!empty($it['referencia'])) {
            $s = $pdo->prepare('SELECT id FROM products WHERE company_id=? AND (referencia=? OR nome=?) LIMIT 1');
            $s->execute([$companyId, $it['referencia'], $nome]);
            $dup = $s->fetchColumn();
        }

        if ($dup) {
            // Atualiza produto existente
            $pdo->prepare('UPDATE products SET nome=?,preco=?,categoria=?,descricao=?,estoque=COALESCE(estoque,0)+?,
                cor=?,tamanho=?,referencia=?,preco_custo=?,desconto=?,imagem=COALESCE(NULLIF(?,\'\'),imagem),updated_at=NOW()
                WHERE id=? AND company_id=?')
                ->execute([$nome,$preco,$it['final_categoria'],$it['final_descricao'],
                    (int)$it['quantidade'],$it['cor'],$it['tamanho'],$it['referencia'],
                    $it['preco_custo'],(float)$it['desconto'],$it['foto_url'],$dup,$companyId]);
        } else {
            $pdo->prepare('INSERT INTO products
                (company_id,nome,descricao,preco,preco_custo,categoria,referencia,
                 cor,tamanho,estoque,desconto,imagem,sizes,ativo,destaque,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),NOW())')
                ->execute([$companyId,$nome,$it['final_descricao'],$preco,$it['preco_custo'],
                    $it['final_categoria'],$it['referencia'],$it['cor'],$it['tamanho'],
                    (int)$it['quantidade'],(float)$it['desconto'],$it['foto_url'],'', $ativar]);
        }

        $pdo->prepare('UPDATE product_import_items SET status="published",updated_at=NOW() WHERE id=? AND company_id=?')
            ->execute([(int)$it['id'], $companyId]);
        $ok++;
    }

    log_action($pdo,(int)$companyId,(int)($_SESSION['user_id']??0),'import_publish',
        "Lote #{$importId}: {$ok} publicados, {$err} erros");

    flash('success', "{$ok} produto(s) publicado(s)".($err?" · {$err} com erro":'').". Ative em Produtos/Serviços se necessário.");
    redirect(rtrim((string)BASE_URL,'/')  .'/products.php');
}

// ── Deletar lote ──
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $row = $pdo->prepare('SELECT stored_path FROM product_imports WHERE id=? AND company_id=?');
    $row->execute([$importId, $companyId]);
    $row = $row->fetch();
    $pdo->prepare('DELETE FROM product_import_items WHERE import_id=? AND company_id=?')->execute([$importId,$companyId]);
    $pdo->prepare('DELETE FROM product_imports WHERE id=? AND company_id=?')->execute([$importId,$companyId]);
    if ($row && !empty($row['stored_path'])) @unlink(__DIR__.'/'.$row['stored_path']);
    flash('success','Lote removido.');
    redirect(ni_url());
}

/* ================================================================
   Verifica colunas extras na tabela (migration segura)
   ================================================================ */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM product_import_items")->fetchAll(PDO::FETCH_COLUMN);
    $need = ['referencia','preco_custo','quantidade','cor','tamanho','foto_url','desconto'];
    foreach ($need as $col) {
        if (!in_array($col, $cols)) {
            $type = in_array($col,['preco_custo','desconto']) ? 'DECIMAL(10,2) DEFAULT NULL'
                  : ($col === 'quantidade' ? 'INT DEFAULT 1' : 'VARCHAR(255) DEFAULT NULL');
            $pdo->exec("ALTER TABLE product_import_items ADD COLUMN `{$col}` {$type}");
        }
    }
} catch (Throwable $e) { /* silencia */ }

/* ================================================================
   RENDER
   ================================================================ */
$action       = $_GET['action'] ?? 'list';
$flashSuccess = get_flash('success');
$flashError   = get_flash('error');

include __DIR__ . '/views/partials/header.php';
?>

<style>
/* ── Cadastro Inteligente UI ── */
.ci-wrap { max-width:1200px; }
.ci-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.ci-card-header { padding:.9rem 1.25rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; }
.ci-card-header h2 { font-size:1rem; font-weight:700; color:#0f172a; }
.ci-card-body { padding:1.25rem; }

/* Topbar */
.ci-topbar { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem; }
.ci-topbar h1 { font-size:1.4rem; font-weight:700; color:#0f172a; }
.ci-topbar p  { font-size:.82rem; color:#64748b; margin-top:.15rem; }

/* Buttons */
.btn { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem 1.1rem; border-radius:8px; font-size:.82rem; font-weight:600; border:none; cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; }
.btn-primary { background:#6366f1; color:#fff; }
.btn-primary:hover { background:#4f46e5; }
.btn-success { background:#16a34a; color:#fff; }
.btn-success:hover { background:#15803d; }
.btn-amber  { background:#f59e0b; color:#fff; }
.btn-amber:hover  { background:#d97706; }
.btn-danger { background:#ef4444; color:#fff; }
.btn-danger:hover { background:#dc2626; }
.btn-ghost  { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
.btn-ghost:hover  { background:#e2e8f0; }
.btn-sm { padding:.35rem .75rem; font-size:.75rem; }
.btn-outline { background:#fff; color:#6366f1; border:1.5px solid #6366f1; }
.btn-outline:hover { background:#f5f3ff; }

/* Flash */
.flash-ok  { padding:.75rem 1.1rem; border-radius:9px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.85rem; margin-bottom:1rem; }
.flash-err { padding:.75rem 1.1rem; border-radius:9px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.85rem; margin-bottom:1rem; }

/* Upload zone */
.upload-zone { border:2px dashed #e2e8f0; border-radius:14px; padding:2.5rem 1.5rem; text-align:center; background:#f8fafc; cursor:pointer; transition:all .2s; }
.upload-zone:hover,.upload-zone.drag { border-color:#6366f1; background:#f5f3ff; }
.upload-zone input { display:none; }
.upload-zone svg { margin:0 auto .75rem; display:block; color:#94a3b8; }
.upload-zone h3 { font-size:1rem; font-weight:600; color:#0f172a; }
.upload-zone p  { font-size:.82rem; color:#64748b; margin-top:.3rem; }
.upload-zone .ext-badge { display:inline-block; background:#e0e7ff; color:#4338ca; font-size:.72rem; font-weight:700; padding:.2rem .6rem; border-radius:6px; margin:.2rem; }
.fname-preview { margin-top:.75rem; font-size:.82rem; font-weight:600; color:#334155; }

/* Status badges */
.sbadge { display:inline-flex; align-items:center; gap:.3rem; font-size:.7rem; font-weight:700; padding:.2rem .6rem; border-radius:20px; text-transform:uppercase; letter-spacing:.04em; }
.sbadge.draft     { background:#f1f5f9; color:#475569; }
.sbadge.approved  { background:#fef9c3; color:#a16207; }
.sbadge.published { background:#dcfce7; color:#15803d; }
.sbadge.error     { background:#fee2e2; color:#dc2626; }
.sbadge.uploaded  { background:#dbeafe; color:#1e40af; }
.sbadge.processed { background:#f0fdf4; color:#15803d; }
.sbadge.failed    { background:#fee2e2; color:#dc2626; }

/* Stats bar */
.stats-bar { display:flex; gap:.5rem; flex-wrap:wrap; margin:.75rem 0; }
.stat-pill { background:#f8fafc; border:1px solid #e2e8f0; border-radius:20px; padding:.3rem .85rem; font-size:.75rem; font-weight:600; color:#475569; }
.stat-pill span { color:#6366f1; font-weight:700; }

/* Products table (review) */
.review-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.review-table thead th { padding:.65rem .75rem; text-align:left; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.review-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.review-table tbody tr:last-child { border-bottom:none; }
.review-table tbody tr:hover { background:#fafafe; }
.review-table td { padding:.6rem .75rem; vertical-align:middle; }

/* Inline edit fields */
.ri { padding:.4rem .6rem; border:1.5px solid #e2e8f0; border-radius:7px; font-size:.8rem; background:#f8fafc; color:#0f172a; outline:none; transition:border-color .15s; width:100%; }
.ri:focus { border-color:#6366f1; background:#fff; }
.ri-price { font-family:monospace; text-align:right; }
.ri-sm { max-width:80px; }
.ri-md { max-width:120px; }

/* Row status indicator */
.row-draft    { border-left:3px solid #e2e8f0; }
.row-approved { border-left:3px solid #f59e0b; }
.row-published{ border-left:3px solid #22c55e; }
.row-error    { border-left:3px solid #ef4444; background:#fef2f2; }

/* Import list cards */
.import-list { display:flex; flex-direction:column; gap:.6rem; }
.import-row { display:flex; align-items:center; gap:1rem; padding:.9rem 1.25rem; background:#fff; border:1px solid #e2e8f0; border-radius:10px; transition:box-shadow .15s; }
.import-row:hover { box-shadow:0 2px 12px rgba(0,0,0,.06); }
.import-icon { width:40px; height:40px; border-radius:10px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:1.1rem; }
.import-icon.csv  { background:#f0fdf4; }
.import-icon.xlsx { background:#dbeafe; }
.import-info { flex:1; min-width:0; }
.import-name { font-size:.875rem; font-weight:600; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.import-meta { font-size:.72rem; color:#94a3b8; margin-top:.15rem; }
.import-actions { display:flex; gap:.4rem; flex-shrink:0; }

/* Pagination */
.pag { display:flex; align-items:center; gap:.35rem; }
.pag a, .pag span { width:30px; height:30px; display:flex; align-items:center; justify-content:center; border-radius:7px; font-size:.78rem; font-weight:600; text-decoration:none; border:1.5px solid #e2e8f0; color:#475569; transition:all .15s; }
.pag a:hover { border-color:#6366f1; color:#6366f1; }
.pag span.cur { background:#6366f1; border-color:#6366f1; color:#fff; }

/* Progress bar */
.prog-bar { height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden; margin-top:.5rem; }
.prog-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#6366f1,#8b5cf6); transition:width .5s; }

/* CSV format hint */
.format-hint { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1rem 1.25rem; }
.format-hint code { background:#e0e7ff; color:#3730a3; padding:.1rem .4rem; border-radius:4px; font-size:.78rem; }
</style>

<?php if ($flashSuccess): ?>
  <div class="flash-ok">✅ <?= sanitize($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="flash-err">⚠️ <?= sanitize($flashError) ?></div>
<?php endif; ?>

<div class="ci-wrap">

<?php /* ===================== LISTAGEM ===================== */ ?>
<?php if ($action === 'list'): ?>

  <div class="ci-topbar">
    <div>
      <h1>📦 Cadastro Inteligente</h1>
      <p>Importe sua nota fiscal ou planilha do fornecedor → revise → publique no estoque</p>
    </div>
    <a href="<?= ni_url('action=create') ?>" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Importar Nova Mercadoria
    </a>
  </div>

  <!-- Como funciona -->
  <div class="ci-card" style="margin-bottom:1.25rem;">
    <div class="ci-card-body" style="padding:1rem 1.25rem;">
      <div style="display:flex;gap:1.5rem;flex-wrap:wrap;">
        <?php foreach([
          ['1','📤','Upload','Envie o CSV da nota fiscal ou planilha do fornecedor'],
          ['2','⚙️','Processar','O sistema extrai nome, qtd, ref, cor, tamanho e preço de custo'],
          ['3','✏️','Revisar','Preencha o preço de venda, desconto e foto de cada produto'],
          ['4','✅','Aprovar','Marque os itens que estão corretos e prontos'],
          ['5','🚀','Publicar','Insere no estoque. Ative na tela de Produtos para aparecer na loja'],
        ] as [$n,$ic,$tit,$desc]): ?>
          <div style="display:flex;align-items:flex-start;gap:.6rem;flex:1;min-width:160px;">
            <div style="width:28px;height:28px;border-radius:8px;background:#6366f1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex-shrink:0;"><?= $n ?></div>
            <div>
              <p style="font-size:.82rem;font-weight:700;color:#0f172a;"><?= $ic ?> <?= $tit ?></p>
              <p style="font-size:.72rem;color:#64748b;"><?= $desc ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php
  $imports = $pdo->prepare('SELECT pi.*, (SELECT COUNT(*) FROM product_import_items WHERE import_id=pi.id AND company_id=pi.company_id) as item_count FROM product_imports pi WHERE pi.company_id=? ORDER BY pi.id DESC LIMIT 30');
  $imports->execute([$companyId]);
  $imports = $imports->fetchAll();
  ?>

  <div class="ci-card">
    <div class="ci-card-header">
      <h2>Últimas importações</h2>
      <span style="font-size:.75rem;color:#94a3b8;"><?= count($imports) ?> lote(s)</span>
    </div>
    <div class="ci-card-body">
      <?php if (empty($imports)): ?>
        <div style="text-align:center;padding:2.5rem;color:#94a3b8;">
          <div style="font-size:2.5rem;margin-bottom:.5rem;">📦</div>
          <p style="font-size:.875rem;">Nenhuma importação ainda. Comece enviando uma planilha do fornecedor.</p>
          <a href="<?= ni_url('action=create') ?>" class="btn btn-primary" style="margin-top:1rem;">Importar agora</a>
        </div>
      <?php else: ?>
        <div class="import-list">
          <?php foreach($imports as $imp): ?>
            <?php
            $ext = strtolower($imp['file_ext']);
            $status = $imp['status'];
            // Stats rápidos
            $stRow = $pdo->prepare('SELECT status, COUNT(*) as c FROM product_import_items WHERE import_id=? AND company_id=? GROUP BY status');
            $stRow->execute([(int)$imp['id'], $companyId]);
            $st = [];
            foreach($stRow->fetchAll() as $r) $st[$r['status']] = (int)$r['c'];
            $total = array_sum($st);
            $approved  = ($st['approved'] ?? 0) + ($st['published'] ?? 0);
            $pct = $total > 0 ? round($approved/$total*100) : 0;
            ?>
            <div class="import-row">
              <div class="import-icon <?= $ext ?>">
                <?= $ext === 'csv' ? '📄' : '📊' ?>
              </div>
              <div class="import-info">
                <p class="import-name"><?= sanitize($imp['original_filename']) ?></p>
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.2rem;">
                  <span class="sbadge <?= $status ?>"><?= $status ?></span>
                  <span style="font-size:.72rem;color:#94a3b8;"><?= $total ?> produtos · <?= $pct ?>% aprovados</span>
                </div>
                <?php if($total > 0): ?>
                  <div class="prog-bar" style="max-width:200px;">
                    <div class="prog-fill" style="width:<?= $pct ?>%"></div>
                  </div>
                <?php endif; ?>
              </div>
              <div class="import-actions">
                <a href="<?= ni_url('action=view&id='.(int)$imp['id']) ?>" class="btn btn-ghost btn-sm">Abrir</a>
                <?php if ($status === 'uploaded'): ?>
                  <a href="<?= ni_url('action=process&id='.(int)$imp['id']) ?>" class="btn btn-primary btn-sm"
                     onclick="return confirm('Processar e extrair produtos?')">Processar</a>
                <?php endif; ?>
                <a href="<?= ni_url('action=delete&id='.(int)$imp['id']) ?>" class="btn btn-danger btn-sm"
                   onclick="return confirm('Remover este lote?')">Remover</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

<?php /* ===================== CREATE ===================== */ ?>
<?php elseif ($action === 'create'): ?>

  <div class="ci-topbar">
    <div>
      <h1>📤 Nova Importação</h1>
      <p>Envie a planilha do fornecedor ou o CSV da nota fiscal de entrada</p>
    </div>
    <a href="<?= ni_url() ?>" class="btn btn-ghost">← Voltar</a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:1.25rem;align-items:start;">

    <!-- Upload form -->
    <div class="ci-card">
      <div class="ci-card-header"><h2>Enviar arquivo</h2></div>
      <div class="ci-card-body">
        <form method="POST" enctype="multipart/form-data" id="upload-form">
          <input type="hidden" name="do_upload" value="1">

          <div class="upload-zone" id="upload-zone" onclick="document.getElementById('file-input').click()">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <h3>Clique ou arraste o arquivo aqui</h3>
            <p>Planilha do fornecedor, NF de entrada ou qualquer tabela com os produtos</p>
            <div style="margin-top:.75rem;">
              <span class="ext-badge">CSV</span>
              <span class="ext-badge">XLSX*</span>
            </div>
            <input type="file" id="file-input" name="file" accept=".csv,.xlsx">
          </div>
          <p id="fname-preview" class="fname-preview" style="display:none;text-align:center;"></p>
          <p style="font-size:.72rem;color:#94a3b8;margin-top:.5rem;text-align:center;">*XLSX: exporte para CSV antes de enviar (File → Save as → CSV UTF-8)</p>

          <div style="margin-top:1.25rem;display:flex;justify-content:flex-end;gap:.6rem;">
            <a href="<?= ni_url() ?>" class="btn btn-ghost">Cancelar</a>
            <button type="submit" class="btn btn-primary" id="upload-btn" disabled>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Enviar arquivo
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Dicas de formato -->
    <div class="ci-card">
      <div class="ci-card-header"><h2>📋 Formato do CSV</h2></div>
      <div class="ci-card-body">
        <p style="font-size:.8rem;color:#475569;margin-bottom:.75rem;">
          O sistema detecta automaticamente as colunas. Mas quanto mais padrão o arquivo, melhor o resultado.
        </p>
        <div class="format-hint">
          <p style="font-size:.75rem;font-weight:700;color:#334155;margin-bottom:.4rem;">Colunas reconhecidas:</p>
          <?php foreach([
            ['nome / produto / título','Nome do produto'],
            ['ref / referencia / sku','Código/referência'],
            ['preco_custo / valor unit','Preço de custo'],
            ['preco_venda / preco','Preço de venda'],
            ['quantidade / qtd / estoque','Quantidade'],
            ['categoria / grupo','Categoria'],
            ['cor / color','Cor'],
            ['tamanho / tam / size','Tamanho/grade'],
          ] as [$col,$desc]): ?>
            <div style="display:flex;gap:.5rem;margin:.25rem 0;align-items:center;">
              <code><?= $col ?></code>
              <span style="font-size:.72rem;color:#64748b;">→ <?= $desc ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <p style="font-size:.72rem;color:#94a3b8;margin-top:.75rem;">
          Separadores reconhecidos: <strong>vírgula</strong>, <strong>ponto-e-vírgula</strong>, <strong>tab</strong>
        </p>
      </div>
    </div>
  </div>

<?php /* ===================== VIEW / REVIEW ===================== */ ?>
<?php elseif ($action === 'view' && isset($_GET['id'])): ?>
  <?php
  $importId = (int)$_GET['id'];
  $imp = $pdo->prepare('SELECT * FROM product_imports WHERE id=? AND company_id=?');
  $imp->execute([$importId, $companyId]);
  $imp = $imp->fetch();
  if (!$imp) { echo '<div class="flash-err">Importação não encontrada.</div>'; include __DIR__.'/views/partials/footer.php'; exit; }

  $page    = max(1,(int)($_GET['page'] ?? 1));
  $perPage = max(10,min(100,(int)($_GET['per_page'] ?? 25)));
  $offset  = ($page-1)*$perPage;

  $filterStatus = $_GET['filter_status'] ?? '';

  $whereStatus = $filterStatus ? " AND status='{$pdo->quote($filterStatus, PDO::PARAM_STR)}'" : '';
  // Safe filter
  $safeFilter  = in_array($filterStatus,['draft','approved','published','error']) ? " AND status='{$filterStatus}'" : '';

  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_import_items WHERE import_id=? AND company_id=? {$safeFilter}");
  $countStmt->execute([$importId, $companyId]);
  $totalItems = (int)$countStmt->fetchColumn();
  $totalPages = max(1,(int)ceil($totalItems/$perPage));

  $itemsStmt = $pdo->prepare("SELECT * FROM product_import_items WHERE import_id=? AND company_id=? {$safeFilter} ORDER BY id ASC LIMIT {$perPage} OFFSET {$offset}");
  $itemsStmt->execute([$importId, $companyId]);
  $items = $itemsStmt->fetchAll();

  $statsStmt = $pdo->prepare('SELECT status, COUNT(*) as c FROM product_import_items WHERE import_id=? AND company_id=? GROUP BY status');
  $statsStmt->execute([$importId, $companyId]);
  $stats = [];
  foreach($statsStmt->fetchAll() as $r) $stats[$r['status']] = (int)$r['c'];
  $totalAll = array_sum($stats);
  ?>

  <div class="ci-topbar">
    <div>
      <h1>📋 Revisar Lote #<?= (int)$imp['id'] ?></h1>
      <p style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:500px;"><?= sanitize($imp['original_filename']) ?></p>
    </div>
    <a href="<?= ni_url() ?>" class="btn btn-ghost">← Voltar</a>
  </div>

  <!-- Status + ações -->
  <div class="ci-card">
    <div class="ci-card-header">
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <span class="sbadge <?= $imp['status'] ?>"><?= $imp['status'] ?></span>
        <span style="font-size:.78rem;color:#64748b;"><?= (int)$imp['total_rows'] ?> linhas no arquivo · <?= $totalAll ?> produtos extraídos</span>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?= ni_url('action=process&id='.$importId) ?>" class="btn btn-ghost btn-sm"
           onclick="return confirm('Reprocessar? Isso recria todos os rascunhos.')">
          ⚙️ Reprocessar
        </a>
        <?php if ($imp['status'] === 'processed'): ?>
          <a href="<?= ni_url('action=approve_all&id='.$importId) ?>" class="btn btn-amber btn-sm"
             onclick="return confirm('Aprovar todos os itens?')">
            ✅ Aprovar todos
          </a>
          <a href="<?= ni_url('action=publish&id='.$importId) ?>" class="btn btn-success btn-sm"
             onclick="return confirm('Publicar itens aprovados? Ficarão como rascunho (ativo=0) em Produtos/Serviços.')">
            🚀 Publicar aprovados
          </a>
        <?php endif; ?>
        <a href="<?= ni_url('action=delete&id='.$importId) ?>" class="btn btn-danger btn-sm"
           onclick="return confirm('Remover este lote inteiramente?')">
          🗑 Remover lote
        </a>
      </div>
    </div>
    <div class="ci-card-body" style="padding:.85rem 1.25rem;">
      <!-- Stats pills -->
      <div class="stats-bar">
        <?php foreach([
          ['all','Todos',$totalAll,'#6366f1'],
          ['draft','Rascunho',$stats['draft']??0,'#64748b'],
          ['approved','Aprovados',$stats['approved']??0,'#d97706'],
          ['published','Publicados',$stats['published']??0,'#16a34a'],
          ['error','Erros',$stats['error']??0,'#dc2626'],
        ] as [$fs,$label,$cnt,$color]):
          $active = ($filterStatus === $fs || ($fs === 'all' && !$filterStatus));
          $qs = $fs !== 'all' ? "action=view&id={$importId}&filter_status={$fs}" : "action=view&id={$importId}";
        ?>
          <a href="<?= ni_url($qs) ?>" style="text-decoration:none;">
            <span class="stat-pill" style="<?= $active ? "background:{$color};color:#fff;border-color:{$color}" : '' ?>">
              <?= $label ?>: <span style="<?= $active?'color:#fff':'' ?>"><?= $cnt ?></span>
            </span>
          </a>
        <?php endforeach; ?>
        <div style="margin-left:auto;display:flex;align-items:center;gap:.5rem;">
          <label style="font-size:.72rem;color:#94a3b8;">Por página:</label>
          <select onchange="location.href='<?= ni_url('action=view&id='.$importId.($filterStatus?'&filter_status='.$filterStatus:'').'&page=1&per_page=') ?>'+this.value"
                  style="padding:.3rem .5rem;border-radius:6px;border:1px solid #e2e8f0;font-size:.75rem;">
            <?php foreach([25,50,100] as $pp): ?>
              <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Progresso -->
      <?php if($totalAll > 0): $pct = round((($stats['approved']??0)+($stats['published']??0))/$totalAll*100); ?>
      <div style="display:flex;align-items:center;gap:.75rem;">
        <div class="prog-bar" style="flex:1;height:8px;">
          <div class="prog-fill" style="width:<?= $pct ?>%"></div>
        </div>
        <span style="font-size:.75rem;font-weight:700;color:#6366f1;"><?= $pct ?>% revisado</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tabela de revisão -->
  <?php if (empty($items)): ?>
    <div class="ci-card">
      <div class="ci-card-body" style="text-align:center;padding:2.5rem;color:#94a3b8;">
        <?php if ($imp['status'] === 'uploaded'): ?>
          <p style="font-size:.9rem;">Arquivo enviado mas ainda não processado.</p>
          <a href="<?= ni_url('action=process&id='.$importId) ?>" class="btn btn-primary" style="margin-top:1rem;"
             onclick="return confirm('Processar e extrair produtos?')">⚙️ Processar agora</a>
        <?php else: ?>
          <p>Nenhum produto encontrado <?= $filterStatus ? "com status '{$filterStatus}'" : '' ?>.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="ci-card">
      <div style="overflow-x:auto;">
        <table class="review-table">
          <thead>
            <tr>
              <th style="width:28px;">#</th>
              <th>Nome do Produto *</th>
              <th>Ref</th>
              <th>Cor</th>
              <th>Tam</th>
              <th>Qtd</th>
              <th>Custo R$</th>
              <th>Venda R$ *</th>
              <th>Desc%</th>
              <th>Categoria</th>
              <th>Foto (URL)</th>
              <th style="width:90px;">Status</th>
              <th style="width:100px;">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($items as $it): ?>
            <tr class="row-<?= $it['status'] ?>">
              <form method="POST">
                <input type="hidden" name="save_item" value="1">
                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <input type="hidden" name="back" value="<?= sanitize(ni_url("action=view&id={$importId}&page={$page}&per_page={$perPage}".($filterStatus?"&filter_status={$filterStatus}":''))) ?>">

                <td style="color:#94a3b8;font-size:.7rem;text-align:center;"><?= (int)$it['row_number'] ?></td>

                <td style="min-width:180px;">
                  <input class="ri" name="final_nome" value="<?= htmlspecialchars((string)($it['final_nome']??'')) ?>" required>
                  <?php if(!empty($it['error_message'])): ?>
                    <p style="font-size:.67rem;color:#dc2626;margin-top:.15rem;"><?= sanitize($it['error_message']) ?></p>
                  <?php endif; ?>
                </td>

                <td><input class="ri ri-sm" name="referencia" value="<?= htmlspecialchars((string)($it['referencia']??'')) ?>" placeholder="REF-001"></td>
                <td><input class="ri ri-sm" name="cor" value="<?= htmlspecialchars((string)($it['cor']??'')) ?>" placeholder="Preto"></td>
                <td><input class="ri ri-sm" name="tamanho" value="<?= htmlspecialchars((string)($it['tamanho']??'')) ?>" placeholder="M"></td>

                <td><input class="ri ri-sm" name="quantidade" type="number" min="0" value="<?= (int)($it['quantidade']??1) ?>"></td>

                <td><input class="ri ri-price ri-md" name="preco_custo" value="<?= $it['preco_custo'] ? number_format((float)$it['preco_custo'],2,',','.') : '' ?>" placeholder="0,00"></td>

                <td>
                  <input class="ri ri-price ri-md" name="final_preco"
                         value="<?= $it['final_preco'] ? number_format((float)$it['final_preco'],2,',','.') : '' ?>"
                         placeholder="0,00" required
                         style="border-color:<?= (!$it['final_preco'] || $it['final_preco']<=0) ? '#f59e0b' : '#e2e8f0' ?>">
                </td>

                <td><input class="ri ri-sm" name="desconto" type="number" min="0" max="100" step="1" value="<?= (int)($it['desconto']??0) ?>" placeholder="0"></td>

                <td><input class="ri ri-md" name="final_categoria" value="<?= htmlspecialchars((string)($it['final_categoria']??'')) ?>" placeholder="Tênis"></td>

                <td style="min-width:140px;">
                  <input class="ri" name="foto_url" value="<?= htmlspecialchars((string)($it['foto_url']??'')) ?>" placeholder="https://...jpg"
                         style="<?= !empty($it['foto_url'])?'border-color:#22c55e':'' ?>">
                  <?php if(!empty($it['foto_url'])): ?>
                    <img src="<?= htmlspecialchars($it['foto_url']) ?>" style="width:32px;height:32px;object-fit:cover;border-radius:4px;margin-top:.2rem;" loading="lazy" onerror="this.style.display='none'">
                  <?php endif; ?>
                </td>

                <td>
                  <span class="sbadge <?= $it['status'] ?>"><?= $it['status'] ?></span>
                </td>

                <td>
                  <div style="display:flex;flex-direction:column;gap:.3rem;">
                    <label style="display:flex;align-items:center;gap:.3rem;font-size:.72rem;cursor:pointer;color:#64748b;">
                      <input type="checkbox" name="approved" value="1" <?= ($it['status']==='approved'||$it['status']==='published')?'checked':'' ?> style="accent-color:#f59e0b;">
                      Aprovado
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm" style="padding:.3rem .6rem;font-size:.72rem;">💾 Salvar</button>
                  </div>
                </td>
              </form>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginação -->
      <?php if ($totalPages > 1): ?>
      <div style="padding:.85rem 1.25rem;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <span style="font-size:.78rem;color:#94a3b8;">
          <?= $offset+1 ?>–<?= min($offset+$perPage,$totalItems) ?> de <?= $totalItems ?> produtos
        </span>
        <div class="pag">
          <?php
          $baseQs = "action=view&id={$importId}&per_page={$perPage}".($filterStatus?"&filter_status={$filterStatus}":'');
          for($pg=1;$pg<=$totalPages;$pg++):
            if ($totalPages > 7 && abs($pg-$page) > 2 && $pg !== 1 && $pg !== $totalPages) {
              if ($pg === 2 || $pg === $totalPages-1) echo '<span style="border:none;width:auto;padding:0 .2rem;">…</span>';
              continue;
            }
          ?>
            <?php if ($pg === $page): ?>
              <span class="cur"><?= $pg ?></span>
            <?php else: ?>
              <a href="<?= ni_url($baseQs.'&page='.$pg) ?>"><?= $pg ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
// Upload zone drag & drop
(function(){
  const zone  = document.getElementById('upload-zone');
  const input = document.getElementById('file-input');
  const btn   = document.getElementById('upload-btn');
  const prev  = document.getElementById('fname-preview');
  if (!zone) return;

  function showFile(name) {
    if (prev) { prev.textContent = '📄 ' + name; prev.style.display = 'block'; }
    if (btn) btn.disabled = false;
    zone.style.borderColor = '#6366f1';
  }

  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('drag');
    if (e.dataTransfer.files[0]) { input.files = e.dataTransfer.files; showFile(e.dataTransfer.files[0].name); }
  });
  input.addEventListener('change', () => { if (input.files[0]) showFile(input.files[0].name); });
})();
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>