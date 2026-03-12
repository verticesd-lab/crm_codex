<?php
/**
 * products_imports.php — Cadastro em Massa (v2)
 *
 * MELHORIAS v2:
 *  ✅ XLSX nativo via ZipArchive (sem Composer, sem dependências externas)
 *  ✅ Painel de markup global: custo × multiplicador → preenche todos os preços de uma vez
 *  ✅ Categoria em lote: aplica 1 categoria para todos os rascunhos
 *  ✅ "Aprovar todos com preço" — 1 clique para aprovar quem já tem preço e nome
 *  ✅ Formulário bulk: salva todos os itens visíveis de uma só vez (1 submit)
 *  ✅ Foto removida do import (adiciona em Produtos após publicar)
 *  ✅ Detecção automática de colunas aprimorada
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
   HELPERS GERAIS
   ================================================================ */

function ni_price($v): ?float {
    if ($v === null || $v === '') return null;
    $s = preg_replace('/[^0-9,\.]/', '', str_replace(['R$',' '], '', trim((string)$v)));
    if (substr_count($s,',') === 1 && substr_count($s,'.') === 0) $s = str_replace(',','.',$s);
    elseif (substr_count($s,',') >= 1 && substr_count($s,'.') >= 1) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
    $f = (float)$s;
    return ($f > 0) ? $f : null;
}

function ni_int($v): int { return max(1,(int)preg_replace('/[^0-9]/','',(string)$v)); }

function ni_norm($s): string { return trim(preg_replace('/\s+/',' ',(string)$s)); }

function ni_hdr($x): string {
    $x = preg_replace('/^\xEF\xBB\xBF/', '', trim(mb_strtolower((string)$x)));
    return str_replace(
        ['á','à','ã','â','é','ê','í','ó','ô','õ','ú','ü','ç'],
        ['a','a','a','a','e','e','i','o','o','o','u','u','c'],
        preg_replace('/\s+/',' ',$x)
    );
}

function ni_detect_delim(string $path): string {
    $s = @file_get_contents($path, false, null, 0, 4096) ?: '';
    $best = ';'; $bc = -1;
    foreach ([',',';',"\t",'|'] as $d) {
        $c = substr_count($s,$d);
        if ($c > $bc) { $bc = $c; $best = $d; }
    }
    return $best;
}

function ni_map_cols(array $header): array {
    $h = array_map('ni_hdr', $header);
    $pick = function(array $aliases) use ($h): ?int {
        foreach ($h as $i => $col)
            foreach ($aliases as $a)
                if (str_contains($col, ni_hdr($a))) return $i;
        return null;
    };
    return [
        'nome'        => $pick(['nome','produto','descricao do produto','descricao','item','titulo','title','description','mercadoria']),
        'referencia'  => $pick(['ref','referencia','codigo','cod','sku','art','artigo']),
        'preco_custo' => $pick(['custo','preco custo','valor custo','preco compra','valor compra','preco unit','preco unitario','valor unit','vl unit','vl. unit','vlr unit']),
        'preco_venda' => $pick(['venda','preco venda','valor venda','preco sugerido','sugerido']),
        'quantidade'  => $pick(['quantidade','qtd','qtde','qty','estoque','saldo','unidades']),
        'categoria'   => $pick(['categoria','grupo','secao','departamento','category','familia','tipo']),
        'cor'         => $pick(['cor','color','couleur']),
        'tamanho'     => $pick(['tamanho','tam','size','grade','numeracao','numero']),
        'descricao'   => $pick(['obs','observacao','detalhe','detalhes','note','notes','complemento','descricao adicional']),
    ];
}

function ni_url(string $qs = ''): string {
    $b = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    return $b . '/products_imports.php' . ($qs ? '?' . $qs : '');
}

function ni_ensure_dir(string $d): void {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}

/* ================================================================
   LEITOR XLSX NATIVO (ZipArchive + SimpleXML — sem Composer)
   Lê a primeira aba da planilha e retorna array de arrays.
   ================================================================ */

function ni_col_to_idx(string $col): int {
    $col = strtoupper(trim($col));
    $idx = 0;
    for ($i = 0; $i < strlen($col); $i++)
        $idx = $idx * 26 + (ord($col[$i]) - 64);
    return $idx - 1;
}

function ni_read_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        throw new Exception('Extensão ZipArchive não disponível no servidor. Exporte para CSV e reimporte.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Não foi possível abrir o arquivo XLSX. O arquivo pode estar corrompido.');
    }

    // 1. Shared strings (textos armazenados centralmente no xlsx)
    $sharedStrings = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw) {
        libxml_use_internal_errors(true);
        $ss = simplexml_load_string($ssRaw);
        if ($ss) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $txt = '';
                    foreach ($si->r as $r) $txt .= (string)$r->t;
                    $sharedStrings[] = $txt;
                }
            }
        }
    }

    // 2. Descobre qual é a sheet1 (pode estar em workbook.xml)
    $sheetFile = 'xl/worksheets/sheet1.xml';
    // Tenta via workbook relationships
    $wbRel = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbRel) {
        libxml_use_internal_errors(true);
        $rel = simplexml_load_string($wbRel);
        if ($rel) {
            foreach ($rel->Relationship as $r) {
                $type = (string)$r['Type'];
                if (str_contains($type, 'worksheet')) {
                    $target = (string)$r['Target'];
                    $sheetFile = 'xl/' . ltrim($target, '/');
                    break; // pega a primeira aba
                }
            }
        }
    }

    $sheetRaw = $zip->getFromName($sheetFile);
    $zip->close();

    if (!$sheetRaw) {
        throw new Exception('Não foi possível ler a planilha dentro do XLSX.');
    }

    libxml_use_internal_errors(true);
    $sheet = simplexml_load_string($sheetRaw);
    if (!$sheet || !isset($sheet->sheetData)) {
        throw new Exception('Formato XLSX inválido ou não suportado. Tente exportar como CSV.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $maxCol = 0;
        foreach ($row->c as $cell) {
            $ref    = preg_replace('/[0-9]/', '', (string)$cell['r']);
            $colIdx = ni_col_to_idx($ref);
            $type   = (string)$cell['t'];
            $val    = isset($cell->v) ? (string)$cell->v : '';

            if ($type === 's') {
                $val = $sharedStrings[(int)$val] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = isset($cell->is->t) ? (string)$cell->is->t : '';
            }
            // Datas Excel (número): mantém como número — pode ser útil

            $cells[$colIdx] = $val;
            $maxCol = max($maxCol, $colIdx);
        }
        if (empty($cells)) continue;

        $arr = [];
        for ($i = 0; $i <= $maxCol; $i++) $arr[] = $cells[$i] ?? '';
        $rows[] = $arr;
    }
    return $rows;
}

/* ================================================================
   FUNÇÃO DE PROCESSAMENTO UNIFICADA (CSV + XLSX)
   ================================================================ */

function ni_process_rows(array $rows, PDO $pdo, int $importId, int $companyId): int {
    if (empty($rows)) throw new Exception('Arquivo vazio ou sem linhas reconhecíveis.');

    $header = array_shift($rows); // primeira linha = cabeçalho
    $map    = ni_map_cols($header);
    if ($map['nome'] === null) $map['nome'] = 0;

    $pdo->prepare('DELETE FROM product_import_items WHERE import_id=? AND company_id=?')
        ->execute([$importId, $companyId]);

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

    $total = 0;
    foreach ($rows as $rowIdx => $row) {
        $rawNome = isset($map['nome']) && $map['nome'] !== null ? ($row[$map['nome']] ?? '') : ($row[0] ?? '');
        $nome    = ni_norm($rawNome);
        if ($nome === '') continue;

        $get = fn($key) => (isset($map[$key]) && $map[$key] !== null) ? ($row[$map[$key]] ?? '') : '';

        $rawPrecoV  = $get('preco_venda');
        $rawPrecoC  = $get('preco_custo');
        $precoVenda = ni_price($rawPrecoV) ?: ni_price($rawPrecoC);
        $precoCusto = ni_price($rawPrecoC);

        $ins->execute([
            ':import_id'  => $importId,
            ':company_id' => $companyId,
            ':row_num'    => $rowIdx + 2,
            ':raw_nome'   => $rawNome,
            ':raw_preco'  => $rawPrecoV ?: $rawPrecoC,
            ':raw_cat'    => $get('categoria'),
            ':raw_desc'   => $get('descricao'),
            ':nome'       => $nome,
            ':preco_venda'=> $precoVenda,
            ':cat'        => ni_norm($get('categoria')),
            ':desc'       => ni_norm($get('descricao')),
            ':ref'        => ni_norm($get('referencia')),
            ':custo'      => $precoCusto,
            ':qty'        => ni_int($get('quantidade') ?: '1'),
            ':cor'        => ni_norm($get('cor')),
            ':tam'        => ni_norm($get('tamanho')),
        ]);
        $total++;
    }
    return $total;
}

/* ================================================================
   ACTIONS — POST / GET
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

    $dir  = __DIR__ . '/uploads/imports';
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

// ── Processar (CSV + XLSX) ──
if (($_GET['action'] ?? '') === 'process' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $imp = $pdo->prepare('SELECT * FROM product_imports WHERE id=? AND company_id=?');
    $imp->execute([$importId, $companyId]);
    $imp = $imp->fetch();
    if (!$imp) { flash('error','Importação não encontrada.'); redirect(ni_url()); }

    $filePath = __DIR__ . '/' . $imp['stored_path'];
    $ext      = strtolower($imp['file_ext']);

    try {
        $pdo->beginTransaction();

        if ($ext === 'csv') {
            if (!file_exists($filePath)) throw new Exception('Arquivo CSV não encontrado no servidor.');
            $delim = ni_detect_delim($filePath);
            $fh    = fopen($filePath, 'r');
            if (!$fh) throw new Exception('Não foi possível abrir o CSV.');

            // Detecta encoding
            $firstLine = fgets($fh); rewind($fh);
            if (mb_detect_encoding($firstLine, 'UTF-8', true) === false) {
                $content = mb_convert_encoding(file_get_contents($filePath), 'UTF-8', 'ISO-8859-1');
                $tmpFile = tempnam(sys_get_temp_dir(), 'ni_') . '.csv';
                file_put_contents($tmpFile, $content);
                fclose($fh);
                $fh = fopen($tmpFile, 'r');
            }

            $rows = [];
            while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                $rows[] = $row;
            }
            fclose($fh);
        } elseif ($ext === 'xlsx') {
            $rows = ni_read_xlsx($filePath);
        } else {
            throw new Exception('Formato não suportado.');
        }

        $total = ni_process_rows($rows, $pdo, $importId, $companyId);

        $pdo->prepare('UPDATE product_imports SET status="processed",total_rows=? WHERE id=? AND company_id=?')
            ->execute([$total, $importId, $companyId]);
        $pdo->commit();

        flash('success', "✅ {$total} produtos extraídos! Use o Painel de Preços para precificar em massa.");
        redirect(ni_url('action=view&id='.$importId));

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('UPDATE product_imports SET status="failed" WHERE id=? AND company_id=?')
            ->execute([$importId, $companyId]);
        flash('error', 'Falha: '.$e->getMessage());
        redirect(ni_url('action=view&id='.$importId));
    }
}

// ── BULK: Aplicar markup global (custo × multiplicador) ──
if (($_GET['action'] ?? '') === 'apply_markup' && isset($_GET['id'])) {
    $importId  = (int)$_GET['id'];
    $markup    = max(1.0, (float)str_replace(',', '.', $_GET['markup'] ?? '2'));
    $overwrite = isset($_GET['overwrite']); // se true, sobrescreve preços já preenchidos

    $where = $overwrite
        ? 'import_id=? AND company_id=? AND preco_custo > 0'
        : 'import_id=? AND company_id=? AND preco_custo > 0 AND (final_preco IS NULL OR final_preco = 0)';

    $items = $pdo->prepare("SELECT id, preco_custo FROM product_import_items WHERE {$where}");
    $items->execute([$importId, $companyId]);
    $items = $items->fetchAll();

    $upd = $pdo->prepare('UPDATE product_import_items SET final_preco=? WHERE id=? AND company_id=?');
    foreach ($items as $it) {
        // Arredonda para .90 ou .00 (ex: 62.9 → 62.90, mas pode customizar)
        $sell = round((float)$it['preco_custo'] * $markup, 2);
        $upd->execute([$sell, (int)$it['id'], $companyId]);
    }

    $n = count($items);
    flash('success', "💰 {$n} preços calculados com markup {$markup}x. Verifique e ajuste individualmente se necessário.");
    redirect(ni_url('action=view&id='.$importId));
}

// ── BULK: Definir categoria para todos os rascunhos ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_category_all'])) {
    $importId = (int)($_POST['import_id'] ?? 0);
    $cat      = ni_norm($_POST['category_all'] ?? '');
    if ($cat && $importId) {
        $stmt = $pdo->prepare("UPDATE product_import_items SET final_categoria=? WHERE import_id=? AND company_id=? AND status IN ('draft','error')");
        $stmt->execute([$cat, $importId, $companyId]);
        flash('success', "🏷️ Categoria '{$cat}' aplicada a {$stmt->rowCount()} itens.");
    }
    redirect(ni_url('action=view&id='.$importId));
}

// ── BULK: Aprovar todos que têm nome + preço válidos ──
if (($_GET['action'] ?? '') === 'approve_priced' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        UPDATE product_import_items
        SET status='approved', updated_at=NOW()
        WHERE import_id=? AND company_id=?
          AND status IN ('draft','error')
          AND final_preco > 0
          AND final_nome IS NOT NULL AND final_nome != ''
    ");
    $stmt->execute([$importId, $companyId]);
    $n = $stmt->rowCount();
    flash('success', "✅ {$n} produtos aprovados automaticamente (tinham nome + preço).");
    redirect(ni_url('action=view&id='.$importId));
}

// ── BULK SAVE: Salva todos os itens visíveis de uma vez ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    $importId = (int)($_POST['import_id'] ?? 0);
    $ids      = array_map('intval', (array)($_POST['item_ids'] ?? []));

    $upd = $pdo->prepare('
        UPDATE product_import_items
        SET final_nome=?, final_preco=?, final_categoria=?,
            referencia=?, preco_custo=?, quantidade=?,
            cor=?, tamanho=?, desconto=?, status=?, updated_at=NOW()
        WHERE id=? AND company_id=?
    ');

    $saved = 0;
    foreach ($ids as $iid) {
        $nome   = ni_norm($_POST["nome_{$iid}"] ?? '');
        $preco  = ni_price($_POST["preco_{$iid}"] ?? null);
        $cat    = ni_norm($_POST["cat_{$iid}"] ?? '');
        $ref    = ni_norm($_POST["ref_{$iid}"] ?? '');
        $custo  = ni_price($_POST["custo_{$iid}"] ?? null);
        $qty    = ni_int($_POST["qty_{$iid}"] ?? '1');
        $cor    = ni_norm($_POST["cor_{$iid}"] ?? '');
        $tam    = ni_norm($_POST["tam_{$iid}"] ?? '');
        $desc   = max(0, min(100, (float)($_POST["desc_{$iid}"] ?? 0)));
        $ok     = isset($_POST["ok_{$iid}"]);
        $status = ($ok && $nome && $preco > 0) ? 'approved' : 'draft';

        if ($nome === '') continue;
        $upd->execute([$nome, $preco, $cat, $ref, $custo, $qty, $cor, $tam, $desc, $status, $iid, $companyId]);
        $saved++;
    }

    flash('success', "💾 {$saved} produto(s) salvos.");
    $page      = (int)($_POST['page'] ?? 1);
    $perPage   = (int)($_POST['per_page'] ?? 50);
    $filterSt  = $_POST['filter_status'] ?? '';
    redirect(ni_url("action=view&id={$importId}&page={$page}&per_page={$perPage}".($filterSt?"&filter_status={$filterSt}":'')));
}

// ── Salvar item individual ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $status = isset($_POST['approved']) ? 'approved' : 'draft';
    $pdo->prepare('
        UPDATE product_import_items SET
            final_nome=?, final_preco=?, final_categoria=?, final_descricao=?,
            referencia=?, preco_custo=?, quantidade=?, cor=?, tamanho=?, desconto=?,
            status=?, updated_at=NOW()
        WHERE id=? AND company_id=?
    ')->execute([
        ni_norm($_POST['final_nome']      ?? ''),
        ni_price($_POST['final_preco']    ?? null),
        ni_norm($_POST['final_categoria'] ?? ''),
        ni_norm($_POST['final_descricao'] ?? ''),
        ni_norm($_POST['referencia']      ?? ''),
        ni_price($_POST['preco_custo']    ?? null),
        ni_int($_POST['quantidade']       ?? 1),
        ni_norm($_POST['cor']             ?? ''),
        ni_norm($_POST['tamanho']         ?? ''),
        (float)($_POST['desconto']        ?? 0),
        $status,
        $itemId, $companyId,
    ]);
    flash('success', 'Produto salvo.');
    redirect($_POST['back'] ?? ni_url());
}

// ── Aprovar todos ──
if (($_GET['action'] ?? '') === 'approve_all' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $pdo->prepare("UPDATE product_import_items SET status='approved' WHERE import_id=? AND company_id=? AND status IN ('draft','error')")
        ->execute([$importId, $companyId]);
    flash('success', 'Todos os itens aprovados.');
    redirect(ni_url('action=view&id='.$importId));
}

// ── Publicar aprovados → products ──
if (($_GET['action'] ?? '') === 'publish' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $ativar   = isset($_GET['ativo']) ? 1 : 0;

    // Garante colunas extras em products
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['referencia'=>'VARCHAR(100)','cor'=>'VARCHAR(80)','estoque'=>'INT DEFAULT 0',
                  'desconto'=>'DECIMAL(5,2) DEFAULT 0','preco_custo'=>'DECIMAL(10,2)',
                  'imagem2'=>'VARCHAR(255)','imagem3'=>'VARCHAR(255)','imagem4'=>'VARCHAR(255)'] as $col => $type) {
            if (!in_array($col, $cols))
                $pdo->exec("ALTER TABLE products ADD COLUMN `{$col}` {$type} DEFAULT NULL");
        }
    } catch(Throwable $e) {}

    $items = $pdo->prepare("SELECT * FROM product_import_items WHERE import_id=? AND company_id=? AND status='approved'");
    $items->execute([$importId, $companyId]);
    $items = $items->fetchAll();

    if (!$items) { flash('error','Nenhum item aprovado para publicar.'); redirect(ni_url('action=view&id='.$importId)); }

    $ok = $err = 0;
    foreach ($items as $it) {
        $nome  = trim((string)$it['final_nome']);
        $preco = (float)($it['final_preco'] ?? 0);
        if ($nome === '' || $preco <= 0) {
            $pdo->prepare("UPDATE product_import_items SET status='error',error_message='Nome vazio ou preço inválido' WHERE id=? AND company_id=?")
                ->execute([(int)$it['id'], $companyId]);
            $err++; continue;
        }

        $dup = null;
        if (!empty($it['referencia'])) {
            $s = $pdo->prepare('SELECT id FROM products WHERE company_id=? AND (referencia=? OR nome=?) LIMIT 1');
            $s->execute([$companyId, $it['referencia'], $nome]);
            $dup = $s->fetchColumn();
        }

        if ($dup) {
            $pdo->prepare('UPDATE products SET nome=?,preco=?,categoria=?,descricao=?,estoque=COALESCE(estoque,0)+?,cor=?,referencia=?,preco_custo=?,desconto=?,updated_at=NOW() WHERE id=? AND company_id=?')
                ->execute([$nome,$preco,$it['final_categoria'],$it['final_descricao'],(int)$it['quantidade'],$it['cor'],$it['referencia'],$it['preco_custo'],(float)$it['desconto'],$dup,$companyId]);
        } else {
            $pdo->prepare('INSERT INTO products (company_id,nome,descricao,preco,preco_custo,categoria,referencia,cor,tamanho,estoque,desconto,sizes,imagem,ativo,destaque,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),NOW())')
                ->execute([$companyId,$nome,$it['final_descricao'],$preco,$it['preco_custo'],$it['final_categoria'],$it['referencia'],$it['cor'],$it['tamanho'],(int)$it['quantidade'],(float)$it['desconto'],'','',$ativar]);
        }

        $pdo->prepare("UPDATE product_import_items SET status='published',updated_at=NOW() WHERE id=? AND company_id=?")
            ->execute([(int)$it['id'], $companyId]);
        $ok++;
    }

    if (function_exists('log_action'))
        log_action($pdo, $companyId, (int)($_SESSION['user_id']??0), 'import_publish', "Lote #{$importId}: {$ok} publicados, {$err} erros");

    flash('success', "🚀 {$ok} produto(s) publicado(s)".($err?" · ⚠️ {$err} com erro":'').". ".($ativar?'Já estão ativos na loja!':'Ative-os em Produtos/Serviços e adicione as fotos.'));
    redirect(rtrim((string)BASE_URL, '/') . '/products.php');
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
   Migration segura de colunas extras
   ================================================================ */
try {
    $cols = $pdo->query('SHOW COLUMNS FROM product_import_items')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['referencia'=>'VARCHAR(255)','preco_custo'=>'DECIMAL(10,2) DEFAULT NULL',
              'quantidade'=>'INT DEFAULT 1','cor'=>'VARCHAR(255) DEFAULT NULL',
              'tamanho'=>'VARCHAR(255) DEFAULT NULL','foto_url'=>'VARCHAR(500) DEFAULT NULL',
              'desconto'=>'DECIMAL(5,2) DEFAULT 0'] as $col => $type) {
        if (!in_array($col, $cols))
            $pdo->exec("ALTER TABLE product_import_items ADD COLUMN `{$col}` {$type}");
    }
} catch (Throwable $e) {}

/* ================================================================
   RENDER
   ================================================================ */
$action       = $_GET['action'] ?? 'list';
$flashSuccess = get_flash('success');
$flashError   = get_flash('error');

include __DIR__ . '/views/partials/header.php';
?>
<style>
.ci-wrap { max-width:1300px; }
.ci-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
.ci-card-header { padding:.9rem 1.25rem; background:#f8fafc; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; }
.ci-card-header h2 { font-size:1rem; font-weight:700; color:#0f172a; }
.ci-card-body { padding:1.25rem; }
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
.btn-amber:hover { background:#d97706; }
.btn-danger { background:#ef4444; color:#fff; }
.btn-danger:hover { background:#dc2626; }
.btn-ghost  { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
.btn-ghost:hover { background:#e2e8f0; }
.btn-sm { padding:.35rem .75rem; font-size:.75rem; }

/* Flash */
.flash-ok  { padding:.75rem 1.1rem; border-radius:9px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:.85rem; margin-bottom:1rem; }
.flash-err { padding:.75rem 1.1rem; border-radius:9px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:.85rem; margin-bottom:1rem; }

/* Upload zone */
.upload-zone { border:2px dashed #e2e8f0; border-radius:14px; padding:2.5rem 1.5rem; text-align:center; background:#f8fafc; cursor:pointer; transition:all .2s; }
.upload-zone:hover,.upload-zone.drag { border-color:#6366f1; background:#f5f3ff; }
.upload-zone input { display:none; }
.upload-zone h3 { font-size:1rem; font-weight:600; color:#0f172a; }
.upload-zone p  { font-size:.82rem; color:#64748b; margin-top:.3rem; }
.ext-badge { display:inline-block; background:#e0e7ff; color:#4338ca; font-size:.72rem; font-weight:700; padding:.2rem .6rem; border-radius:6px; margin:.2rem; }

/* Status badges */
.sbadge { display:inline-flex; align-items:center; gap:.3rem; font-size:.7rem; font-weight:700; padding:.2rem .6rem; border-radius:20px; text-transform:uppercase; letter-spacing:.04em; }
.sbadge.draft     { background:#f1f5f9; color:#475569; }
.sbadge.approved  { background:#fef9c3; color:#a16207; }
.sbadge.published { background:#dcfce7; color:#15803d; }
.sbadge.error     { background:#fee2e2; color:#dc2626; }
.sbadge.uploaded  { background:#dbeafe; color:#1e40af; }
.sbadge.processed { background:#f0fdf4; color:#15803d; }
.sbadge.failed    { background:#fee2e2; color:#dc2626; }

/* Stats */
.stat-pill { background:#f8fafc; border:1px solid #e2e8f0; border-radius:20px; padding:.3rem .85rem; font-size:.75rem; font-weight:600; color:#475569; text-decoration:none; }
.stat-pill span { color:#6366f1; font-weight:700; }
.prog-bar { height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden; margin-top:.5rem; }
.prog-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#6366f1,#8b5cf6); }

/* ── Painel de ações em massa ── */
.bulk-toolbar {
    background:linear-gradient(135deg,#1e1b4b,#312e81);
    border-radius:12px;
    padding:1rem 1.25rem;
    margin-bottom:1rem;
    display:flex;
    flex-wrap:wrap;
    gap:1rem;
    align-items:flex-end;
}
.bulk-toolbar h3 { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.08em; color:#a5b4fc; margin-bottom:.5rem; }
.bulk-group { display:flex; flex-direction:column; gap:.25rem; }
.bulk-input { padding:.45rem .75rem; border-radius:8px; border:1.5px solid #4338ca; background:#1e1b4b; color:#fff; font-size:.82rem; outline:none; min-width:140px; }
.bulk-input:focus { border-color:#818cf8; }
.bulk-btn { padding:.45rem .9rem; border-radius:8px; border:none; font-size:.78rem; font-weight:700; cursor:pointer; }
.bulk-btn-indigo { background:#6366f1; color:#fff; }
.bulk-btn-indigo:hover { background:#4f46e5; }
.bulk-btn-amber  { background:#f59e0b; color:#fff; }
.bulk-btn-amber:hover  { background:#d97706; }
.bulk-btn-green  { background:#16a34a; color:#fff; }
.bulk-btn-green:hover  { background:#15803d; }
.bulk-separator { width:1px; background:rgba(255,255,255,.15); align-self:stretch; margin:.2rem 0; }

/* Review table */
.review-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.review-table thead { position:sticky; top:0; z-index:10; }
.review-table thead th { padding:.6rem .55rem; text-align:left; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; background:#f8fafc; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.review-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.review-table tbody tr:hover { background:#fafafe; }
.review-table td { padding:.45rem .55rem; vertical-align:middle; }
.row-draft    { border-left:3px solid #e2e8f0; }
.row-approved { border-left:3px solid #f59e0b; background:#fffbeb; }
.row-published{ border-left:3px solid #22c55e; background:#f0fdf4; }
.row-error    { border-left:3px solid #ef4444; background:#fef2f2; }

/* Inline inputs */
.ri { padding:.38rem .55rem; border:1.5px solid #e2e8f0; border-radius:6px; font-size:.78rem; background:#f8fafc; color:#0f172a; outline:none; transition:border-color .15s; width:100%; box-sizing:border-box; }
.ri:focus { border-color:#6366f1; background:#fff; }
.ri.warn  { border-color:#f59e0b; background:#fffbeb; }
.ri.ok    { border-color:#22c55e; }
.ri-xs  { max-width:58px; }
.ri-sm  { max-width:80px; }
.ri-md  { max-width:110px; }
.ri-num { text-align:right; font-family:monospace; }

/* Checkbox estilizado */
.chk-ok { accent-color:#22c55e; width:16px; height:16px; cursor:pointer; }

/* Paginação */
.pag { display:flex; align-items:center; gap:.35rem; flex-wrap:wrap; }
.pag a,.pag span { width:30px; height:30px; display:flex; align-items:center; justify-content:center; border-radius:7px; font-size:.78rem; font-weight:600; text-decoration:none; border:1.5px solid #e2e8f0; color:#475569; }
.pag a:hover { border-color:#6366f1; color:#6366f1; }
.pag span.cur { background:#6366f1; border-color:#6366f1; color:#fff; }

/* Import list */
.import-row { display:flex; align-items:center; gap:1rem; padding:.9rem 1.25rem; background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:.5rem; transition:box-shadow .15s; }
.import-row:hover { box-shadow:0 2px 12px rgba(0,0,0,.06); }

/* Format hint */
.format-hint { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:1rem; }
.format-hint code { background:#e0e7ff; color:#3730a3; padding:.1rem .35rem; border-radius:4px; font-size:.76rem; }

@media (max-width:768px) {
    .bulk-toolbar { flex-direction:column; }
    .review-table { font-size:.72rem; }
    .ri { font-size:.72rem; padding:.3rem .4rem; }
}
</style>

<?php if ($flashSuccess): ?>
  <div class="flash-ok">✅ <?= sanitize($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="flash-err">⚠️ <?= sanitize($flashError) ?></div>
<?php endif; ?>

<div class="ci-wrap">

<?php /* ======================================================== LISTAGEM */
if ($action === 'list'): ?>

  <div class="ci-topbar">
    <div>
      <h1>📦 Cadastro em Massa</h1>
      <p>Importe planilha do fornecedor → precifique em lote → publique no estoque</p>
    </div>
    <a href="<?= ni_url('action=create') ?>" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Importar Nova Mercadoria
    </a>
  </div>

  <!-- Fluxo visual -->
  <div class="ci-card" style="margin-bottom:1.25rem;">
    <div class="ci-card-body" style="padding:.9rem 1.25rem;">
      <div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:flex-start;">
        <?php foreach([
          ['1','📤','Upload CSV ou XLSX','Planilha do fornecedor, NF ou qualquer tabela com os produtos'],
          ['2','⚙️','Processar','Extrai nome, referência, cor, tamanho, custo automaticamente'],
          ['3','💰','Precificar em lote','Define markup global (ex: 2.5×) e categoria — 1 clique'],
          ['4','✅','Aprovar com 1 clique','Aprova todos que já têm nome e preço preenchidos'],
          ['5','🚀','Publicar','Insere no estoque. Adicione as fotos depois em Produtos'],
        ] as [$n,$ic,$tit,$desc]): ?>
          <div style="display:flex;align-items:flex-start;gap:.55rem;flex:1;min-width:150px;">
            <div style="width:24px;height:24px;border-radius:7px;background:#6366f1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;flex-shrink:0;"><?= $n ?></div>
            <div>
              <p style="font-size:.8rem;font-weight:700;color:#0f172a;"><?= $ic ?> <?= $tit ?></p>
              <p style="font-size:.7rem;color:#64748b;"><?= $desc ?></p>
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
          <p style="font-size:.875rem;">Nenhuma importação ainda. Comece enviando a planilha do fornecedor.</p>
          <a href="<?= ni_url('action=create') ?>" class="btn btn-primary" style="margin-top:1rem;">Importar agora</a>
        </div>
      <?php else: ?>
        <?php foreach($imports as $imp):
          $ext    = strtolower($imp['file_ext']);
          $status = $imp['status'];
          $stRow  = $pdo->prepare('SELECT status, COUNT(*) as c FROM product_import_items WHERE import_id=? AND company_id=? GROUP BY status');
          $stRow->execute([(int)$imp['id'], $companyId]);
          $st = [];
          foreach($stRow->fetchAll() as $r) $st[$r['status']] = (int)$r['c'];
          $totalIt  = array_sum($st);
          $approved = ($st['approved'] ?? 0) + ($st['published'] ?? 0);
          $pct      = $totalIt > 0 ? round($approved/$totalIt*100) : 0;
        ?>
          <div class="import-row">
            <div style="width:40px;height:40px;border-radius:10px;background:<?= $ext==='xlsx'?'#dbeafe':'#f0fdf4' ?>;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
              <?= $ext === 'xlsx' ? '📊' : '📄' ?>
            </div>
            <div style="flex:1;min-width:0;">
              <p style="font-size:.875rem;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($imp['original_filename']) ?></p>
              <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.2rem;">
                <span class="sbadge <?= $status ?>"><?= $status ?></span>
                <span style="font-size:.72rem;color:#94a3b8;"><?= $totalIt ?> produtos · <?= $pct ?>% aprovados</span>
              </div>
              <?php if($totalIt > 0): ?>
                <div class="prog-bar" style="max-width:220px;"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:.4rem;flex-shrink:0;flex-wrap:wrap;">
              <a href="<?= ni_url('action=view&id='.(int)$imp['id']) ?>" class="btn btn-ghost btn-sm">Abrir</a>
              <?php if ($status === 'uploaded'): ?>
                <a href="<?= ni_url('action=process&id='.(int)$imp['id']) ?>" class="btn btn-primary btn-sm"
                   onclick="return confirm('Processar e extrair produtos?')">⚙️ Processar</a>
              <?php endif; ?>
              <a href="<?= ni_url('action=delete&id='.(int)$imp['id']) ?>" class="btn btn-danger btn-sm"
                 onclick="return confirm('Remover este lote?')">Remover</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

<?php /* ======================================================== CREATE */
elseif ($action === 'create'): ?>

  <div class="ci-topbar">
    <div><h1>📤 Nova Importação</h1><p>Envie a planilha do fornecedor — CSV ou Excel (XLSX)</p></div>
    <a href="<?= ni_url() ?>" class="btn btn-ghost">← Voltar</a>
  </div>

  <div style="display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start;">

    <div class="ci-card">
      <div class="ci-card-header"><h2>Enviar arquivo</h2></div>
      <div class="ci-card-body">
        <form method="POST" enctype="multipart/form-data" id="upload-form">
          <input type="hidden" name="do_upload" value="1">
          <label class="upload-zone" id="upload-zone" for="file-input">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto .75rem;display:block;color:#94a3b8;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <h3>Clique ou arraste o arquivo aqui</h3>
            <p>Planilha do fornecedor, NF de entrada ou qualquer tabela com produtos</p>
            <div style="margin-top:.75rem;">
              <span class="ext-badge">CSV</span>
              <span class="ext-badge" style="background:#dbeafe;color:#1e40af;">XLSX ✅</span>
            </div>
            <p id="fname-preview" style="display:none;margin-top:.75rem;font-size:.85rem;font-weight:600;color:#334155;"></p>
            <input type="file" id="file-input" name="file" accept=".csv,.xlsx">
          </label>

          <div style="margin-top:1.25rem;display:flex;justify-content:flex-end;gap:.6rem;">
            <a href="<?= ni_url() ?>" class="btn btn-ghost">Cancelar</a>
            <button type="submit" class="btn btn-primary" id="upload-btn" disabled>
              📤 Enviar e Processar
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Dicas -->
    <div class="ci-card">
      <div class="ci-card-header"><h2>📋 Colunas reconhecidas</h2></div>
      <div class="ci-card-body">
        <p style="font-size:.78rem;color:#475569;margin-bottom:.75rem;">
          O sistema detecta automaticamente. Coloque cabeçalho na <strong>primeira linha</strong>.
        </p>
        <div class="format-hint">
          <?php foreach([
            ['nome / produto / título','Nome do produto ✦'],
            ['ref / referencia / sku','Código/referência'],
            ['custo / preco unit / vl unit','Preço de custo ✦'],
            ['preco_venda / sugerido','Preço de venda'],
            ['quantidade / qtd / estoque','Quantidade em estoque'],
            ['categoria / grupo / tipo','Categoria'],
            ['cor / color','Cor'],
            ['tamanho / tam / size / grade','Tamanho/grade'],
          ] as [$col,$desc]): ?>
            <div style="display:flex;gap:.5rem;margin:.25rem 0;align-items:baseline;">
              <code><?= $col ?></code>
              <span style="font-size:.7rem;color:#64748b;">→ <?= $desc ?></span>
            </div>
          <?php endforeach; ?>
          <p style="font-size:.7rem;color:#94a3b8;margin-top:.6rem;">✦ = usadas no cálculo de markup automático</p>
        </div>
        <div style="margin-top:.85rem;padding:.75rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
          <p style="font-size:.75rem;font-weight:700;color:#92400e;margin-bottom:.25rem;">💡 Dica para lojas de roupa</p>
          <p style="font-size:.72rem;color:#78350f;">Se sua planilha tem 1 linha por SKU (cor+tamanho juntos), o sistema importa direto. Se tiver grade em colunas separadas (P, M, G...) exporte como CSV e ajuste antes de enviar.</p>
        </div>
      </div>
    </div>
  </div>

<?php /* ======================================================== VIEW / REVIEW */
elseif ($action === 'view' && isset($_GET['id'])): ?>
  <?php
  $importId = (int)$_GET['id'];
  $imp = $pdo->prepare('SELECT * FROM product_imports WHERE id=? AND company_id=?');
  $imp->execute([$importId, $companyId]);
  $imp = $imp->fetch();
  if (!$imp) { echo '<div class="flash-err">Importação não encontrada.</div>'; include __DIR__.'/views/partials/footer.php'; exit; }

  $page         = max(1, (int)($_GET['page'] ?? 1));
  $perPage      = in_array((int)($_GET['per_page'] ?? 50), [25,50,100]) ? (int)$_GET['per_page'] : 50;
  $offset       = ($page - 1) * $perPage;
  $filterStatus = in_array($_GET['filter_status'] ?? '', ['draft','approved','published','error']) ? $_GET['filter_status'] : '';
  $safeFilter   = $filterStatus ? " AND status='{$filterStatus}'" : '';

  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_import_items WHERE import_id=? AND company_id=? {$safeFilter}");
  $countStmt->execute([$importId, $companyId]);
  $totalItems = (int)$countStmt->fetchColumn();
  $totalPages = max(1, (int)ceil($totalItems / $perPage));

  $itemsStmt = $pdo->prepare("SELECT * FROM product_import_items WHERE import_id=? AND company_id=? {$safeFilter} ORDER BY id ASC LIMIT {$perPage} OFFSET {$offset}");
  $itemsStmt->execute([$importId, $companyId]);
  $items = $itemsStmt->fetchAll();

  $statsStmt = $pdo->prepare('SELECT status, COUNT(*) c FROM product_import_items WHERE import_id=? AND company_id=? GROUP BY status');
  $statsStmt->execute([$importId, $companyId]);
  $stats = [];
  foreach($statsStmt->fetchAll() as $r) $stats[$r['status']] = (int)$r['c'];
  $totalAll = array_sum($stats);
  $semPreco = $pdo->prepare("SELECT COUNT(*) FROM product_import_items WHERE import_id=? AND company_id=? AND (final_preco IS NULL OR final_preco=0) AND status='draft'");
  $semPreco->execute([$importId, $companyId]);
  $semPreco = (int)$semPreco->fetchColumn();
  ?>

  <div class="ci-topbar">
    <div>
      <h1>📋 Lote #<?= (int)$imp['id'] ?></h1>
      <p><?= sanitize($imp['original_filename']) ?> · <?= $totalAll ?> produtos</p>
    </div>
    <a href="<?= ni_url() ?>" class="btn btn-ghost">← Voltar</a>
  </div>

  <!-- Barra de status + ações de lote -->
  <div class="ci-card">
    <div class="ci-card-header" style="flex-wrap:wrap;gap:.5rem;">
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <span class="sbadge <?= $imp['status'] ?>"><?= $imp['status'] ?></span>
        <span style="font-size:.78rem;color:#64748b;"><?= $totalAll ?> produtos · <?= ($stats['published']??0)+($stats['approved']??0) ?> revisados · <?= $semPreco ?> sem preço</span>
      </div>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
        <a href="<?= ni_url('action=process&id='.$importId) ?>" class="btn btn-ghost btn-sm"
           onclick="return confirm('Reprocessar? Isso recria todos os rascunhos.')">⚙️ Reprocessar</a>
        <?php if (($stats['draft']??0) > 0 || ($stats['approved']??0) > 0): ?>
          <a href="<?= ni_url('action=approve_priced&id='.$importId) ?>" class="btn btn-amber btn-sm"
             onclick="return confirm('Aprovar todos que têm nome + preço preenchidos?')">✅ Aprovar com preço (<?= ($stats['draft']??0) ?>)</a>
        <?php endif; ?>
        <?php if (($stats['approved']??0) > 0): ?>
          <a href="<?= ni_url('action=publish&id='.$importId.'&ativo=1') ?>" class="btn btn-success btn-sm"
             onclick="return confirm('Publicar e ativar <?= $stats['approved'] ?> produto(s) na loja?')">🚀 Publicar ativos (<?= $stats['approved']??0 ?>)</a>
          <a href="<?= ni_url('action=publish&id='.$importId) ?>" class="btn btn-ghost btn-sm"
             onclick="return confirm('Publicar como rascunho (inativo)?')">Publicar rascunho</a>
        <?php endif; ?>
        <a href="<?= ni_url('action=delete&id='.$importId) ?>" class="btn btn-danger btn-sm"
           onclick="return confirm('Remover este lote inteiro?')">🗑 Remover</a>
      </div>
    </div>

    <div class="ci-card-body" style="padding:.75rem 1.25rem;">
      <!-- Progresso -->
      <?php if($totalAll > 0): $pct = round((($stats['approved']??0)+($stats['published']??0))/$totalAll*100); ?>
      <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem;">
        <div class="prog-bar" style="flex:1;height:8px;"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
        <span style="font-size:.78rem;font-weight:700;color:#6366f1;"><?= $pct ?>% revisado</span>
      </div>
      <?php endif; ?>

      <!-- Filtros de status -->
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
        <?php foreach([
          ['','Todos',$totalAll,'#6366f1'],
          ['draft','Rascunho',$stats['draft']??0,'#64748b'],
          ['approved','Aprovados',$stats['approved']??0,'#d97706'],
          ['published','Publicados',$stats['published']??0,'#16a34a'],
          ['error','Erros',$stats['error']??0,'#dc2626'],
        ] as [$fs,$label,$cnt,$color]):
          $active = ($filterStatus === $fs);
          $qs = $fs ? "action=view&id={$importId}&filter_status={$fs}" : "action=view&id={$importId}";
        ?>
          <a href="<?= ni_url($qs) ?>" class="stat-pill"
             style="<?= $active ? "background:{$color};color:#fff;border-color:{$color};" : '' ?>">
            <?= $label ?>: <span style="<?= $active?'color:#fff':'' ?>"><?= $cnt ?></span>
          </a>
        <?php endforeach; ?>

        <div style="margin-left:auto;display:flex;align-items:center;gap:.5rem;">
          <label style="font-size:.72rem;color:#94a3b8;">Por página:</label>
          <select onchange="location.href='<?= ni_url("action=view&id={$importId}".($filterStatus?"&filter_status={$filterStatus}":'').'&page=1&per_page=') ?>'+this.value"
                  style="padding:.3rem .5rem;border-radius:6px;border:1px solid #e2e8f0;font-size:.75rem;">
            <?php foreach([25,50,100] as $pp): ?>
              <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?>/página</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <?php if (empty($items)): ?>
    <div class="ci-card">
      <div class="ci-card-body" style="text-align:center;padding:2.5rem;color:#94a3b8;">
        <?php if ($imp['status'] === 'uploaded'): ?>
          <p>Arquivo enviado mas não processado ainda.</p>
          <a href="<?= ni_url('action=process&id='.$importId) ?>" class="btn btn-primary" style="margin-top:1rem;"
             onclick="return confirm('Processar agora?')">⚙️ Processar agora</a>
        <?php else: ?>
          <p>Nenhum produto<?= $filterStatus ? " com status '{$filterStatus}'" : '' ?>.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>

    <!-- ════ PAINEL DE AÇÕES EM MASSA ════ -->
    <?php if (!$filterStatus || $filterStatus === 'draft'): ?>
    <div class="bulk-toolbar">
      <!-- Grupo 1: Markup global -->
      <div class="bulk-group">
        <h3>💰 Markup Global (custo × multiplicador)</h3>
        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
          <input type="number" id="markup-val" value="2.5" min="1" step="0.1"
                 class="bulk-input" style="max-width:90px;" placeholder="2.5">
          <span style="color:#a5b4fc;font-size:.8rem;">× custo =</span>
          <span id="markup-preview" style="color:#fde68a;font-size:.8rem;font-weight:700;"></span>
          <a id="markup-btn" href="#" class="bulk-btn bulk-btn-indigo"
             onclick="applyMarkup(); return false;">Calcular preços</a>
          <label style="font-size:.72rem;color:#818cf8;display:flex;align-items:center;gap:.3rem;cursor:pointer;">
            <input type="checkbox" id="overwrite-chk" style="accent-color:#818cf8;">
            Sobrescrever existentes
          </label>
        </div>
        <p style="font-size:.68rem;color:#818cf8;margin-top:.2rem;">Aplica a todos os itens que têm preço de custo. Sem custo = mantém em branco.</p>
      </div>

      <div class="bulk-separator"></div>

      <!-- Grupo 2: Categoria em lote -->
      <form method="POST" style="display:contents;">
        <input type="hidden" name="set_category_all" value="1">
        <input type="hidden" name="import_id" value="<?= $importId ?>">
        <div class="bulk-group">
          <h3>🏷️ Categoria para todos os rascunhos</h3>
          <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
            <input type="text" name="category_all" list="cats-list" class="bulk-input"
                   placeholder="Ex: Calçados masculinos" required>
            <datalist id="cats-list">
              <?php
              $catsExist = $pdo->prepare('SELECT DISTINCT final_categoria FROM product_import_items WHERE import_id=? AND company_id=? AND final_categoria != "" ORDER BY final_categoria');
              $catsExist->execute([$importId, $companyId]);
              foreach($catsExist->fetchAll(PDO::FETCH_COLUMN) as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>">
              <?php endforeach; ?>
            </datalist>
            <button type="submit" class="bulk-btn bulk-btn-amber">Aplicar categoria</button>
          </div>
          <p style="font-size:.68rem;color:#818cf8;margin-top:.2rem;">Sobrescreve a categoria de todos os itens com status Rascunho.</p>
        </div>
      </form>

      <div class="bulk-separator"></div>

      <!-- Grupo 3: Salvar tudo -->
      <div class="bulk-group" style="justify-content:flex-end;">
        <h3>💾 Esta página</h3>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
          <button form="bulk-form" type="submit" class="bulk-btn bulk-btn-green">
            💾 Salvar todos (<?= count($items) ?>)
          </button>
          <button form="bulk-form" type="button" onclick="checkAllOk()" class="bulk-btn" style="background:#312e81;color:#c7d2fe;">
            ☑️ Marcar todos OK
          </button>
        </div>
        <p style="font-size:.68rem;color:#818cf8;margin-top:.2rem;">Salva e aprova os itens desta página de uma vez.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- ════ TABELA DE REVISÃO — 1 form para todos ════ -->
    <div class="ci-card">
      <form method="POST" id="bulk-form">
        <input type="hidden" name="bulk_save" value="1">
        <input type="hidden" name="import_id" value="<?= $importId ?>">
        <input type="hidden" name="page" value="<?= $page ?>">
        <input type="hidden" name="per_page" value="<?= $perPage ?>">
        <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filterStatus) ?>">

        <div style="overflow-x:auto;">
          <table class="review-table">
            <thead>
              <tr>
                <th style="width:24px;">#</th>
                <th style="min-width:160px;">Nome *</th>
                <th style="min-width:70px;">Ref</th>
                <th style="min-width:60px;">Cor</th>
                <th style="min-width:52px;">Tam</th>
                <th style="min-width:46px;">Qtd</th>
                <th style="min-width:85px;">Custo R$</th>
                <th style="min-width:85px;">Venda R$ *</th>
                <th style="min-width:48px;">Desc%</th>
                <th style="min-width:120px;">Categoria</th>
                <th style="width:60px;">Status</th>
                <th style="width:42px;">✅</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($items as $it):
                $missingPrice = (!$it['final_preco'] || $it['final_preco'] <= 0);
                $hasCost = $it['preco_custo'] > 0;
              ?>
              <tr class="row-<?= $it['status'] ?>">
                <input type="hidden" name="item_ids[]" value="<?= (int)$it['id'] ?>">

                <td style="color:#94a3b8;font-size:.68rem;text-align:center;"><?= (int)$it['row_number'] ?></td>

                <td>
                  <input class="ri" name="nome_<?= (int)$it['id'] ?>"
                         value="<?= htmlspecialchars((string)($it['final_nome']??'')) ?>" required>
                  <?php if(!empty($it['error_message'])): ?>
                    <p style="font-size:.65rem;color:#dc2626;margin-top:.1rem;"><?= sanitize($it['error_message']) ?></p>
                  <?php endif; ?>
                </td>

                <td><input class="ri ri-xs" name="ref_<?= (int)$it['id'] ?>" value="<?= htmlspecialchars((string)($it['referencia']??'')) ?>" placeholder="REF"></td>
                <td><input class="ri ri-xs" name="cor_<?= (int)$it['id'] ?>" value="<?= htmlspecialchars((string)($it['cor']??'')) ?>" placeholder="Cor"></td>
                <td><input class="ri ri-xs" name="tam_<?= (int)$it['id'] ?>" value="<?= htmlspecialchars((string)($it['tamanho']??'')) ?>" placeholder="M"></td>
                <td><input class="ri ri-xs ri-num" type="number" min="0" name="qty_<?= (int)$it['id'] ?>" value="<?= (int)($it['quantidade']??1) ?>"></td>

                <td>
                  <input class="ri ri-sm ri-num <?= $hasCost?'ok':'' ?>"
                         name="custo_<?= (int)$it['id'] ?>"
                         value="<?= $it['preco_custo'] ? number_format((float)$it['preco_custo'],2,',','.') : '' ?>"
                         placeholder="0,00"
                         data-cost="<?= (float)$it['preco_custo'] ?>">
                </td>

                <td>
                  <input class="ri ri-sm ri-num <?= $missingPrice ? 'warn' : 'ok' ?>"
                         name="preco_<?= (int)$it['id'] ?>"
                         value="<?= $it['final_preco'] ? number_format((float)$it['final_preco'],2,',','.') : '' ?>"
                         placeholder="0,00"
                         required>
                </td>

                <td><input class="ri ri-xs ri-num" type="number" min="0" max="100" name="desc_<?= (int)$it['id'] ?>" value="<?= (int)($it['desconto']??0) ?>" placeholder="0"></td>

                <td><input class="ri ri-md" name="cat_<?= (int)$it['id'] ?>" value="<?= htmlspecialchars((string)($it['final_categoria']??'')) ?>" placeholder="Ex: Tênis" list="cats-list"></td>

                <td>
                  <span class="sbadge <?= $it['status'] ?>" style="font-size:.6rem;"><?= mb_substr($it['status'],0,3) ?></span>
                </td>

                <td style="text-align:center;">
                  <input type="checkbox" class="chk-ok"
                         name="ok_<?= (int)$it['id'] ?>"
                         <?= in_array($it['status'],['approved','published']) ? 'checked' : '' ?>>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Rodapé: salvar + paginar -->
        <div style="padding:.85rem 1.25rem;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;">
          <div style="display:flex;align-items:center;gap:.5rem;">
            <span style="font-size:.78rem;color:#94a3b8;"><?= $offset+1 ?>–<?= min($offset+$perPage,$totalItems) ?> de <?= $totalItems ?></span>
            <button type="submit" class="btn btn-success btn-sm">💾 Salvar página</button>
          </div>

          <?php if ($totalPages > 1): ?>
          <div class="pag">
            <?php
            $baseQs = "action=view&id={$importId}&per_page={$perPage}".($filterStatus?"&filter_status={$filterStatus}":'');
            for($pg=1;$pg<=$totalPages;$pg++):
              if ($totalPages > 7 && abs($pg-$page) > 2 && $pg !== 1 && $pg !== $totalPages) {
                if ($pg===2||$pg===$totalPages-1) echo '<span style="border:none;width:auto;">…</span>';
                continue;
              }
            ?>
              <?php if ($pg===$page): ?><span class="cur"><?= $pg ?></span>
              <?php else: ?><a href="<?= ni_url($baseQs.'&page='.$pg) ?>"><?= $pg ?></a><?php endif; ?>
            <?php endfor; ?>
          </div>
          <?php endif; ?>
        </div>
      </form>
    </div>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
// ── Upload zone ──
(function(){
  const input = document.getElementById('file-input');
  const btn   = document.getElementById('upload-btn');
  const prev  = document.getElementById('fname-preview');
  const zone  = document.getElementById('upload-zone');
  if (!input) return;
  function showFile(name) {
    if (prev) { prev.textContent = '📄 ' + name; prev.style.display = 'block'; }
    if (btn)  { btn.disabled = false; }
    if (zone) { zone.style.borderColor = '#6366f1'; }
  }
  input.addEventListener('change', () => { if (input.files[0]) showFile(input.files[0].name); });
  if (zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
    zone.addEventListener('drop', e => {
      e.preventDefault(); zone.classList.remove('drag');
      if (e.dataTransfer.files[0]) { input.files = e.dataTransfer.files; showFile(e.dataTransfer.files[0].name); }
    });
  }
})();

// ── Markup global ──
function applyMarkup() {
  const markup   = parseFloat(document.getElementById('markup-val')?.value || '2.5');
  const overwrite = document.getElementById('overwrite-chk')?.checked;
  const importId = <?= (int)($imp['id'] ?? 0) ?>;
  if (!markup || markup < 1) { alert('Informe um multiplicador válido (ex: 2.5)'); return; }
  const ow = overwrite ? '&overwrite=1' : '';
  const msg = overwrite
    ? `Recalcular TODOS os preços com markup ${markup}x (sobrescreve os existentes)?`
    : `Calcular preços de venda com markup ${markup}x apenas onde não há preço?`;
  if (confirm(msg)) {
    location.href = `<?= ni_url('action=apply_markup&id=') ?>${importId}&markup=${markup}${ow}`;
  }
}

// Preview de markup na interface
(function(){
  const inp = document.getElementById('markup-val');
  const prev = document.getElementById('markup-preview');
  if (!inp || !prev) return;
  function update() {
    const v = parseFloat(inp.value);
    if (v >= 1) prev.textContent = `Custo R$50 → Venda R$${(50*v).toFixed(2).replace('.',',')}`;
  }
  inp.addEventListener('input', update);
  update();
})();

// ── Marcar todos como OK ──
function checkAllOk() {
  document.querySelectorAll('.chk-ok').forEach(c => c.checked = true);
}

// ── Auto-highlight: preço em branco = warn ──
document.querySelectorAll('[name^="preco_"]').forEach(inp => {
  inp.addEventListener('input', function() {
    const v = parseFloat(this.value.replace(',','.'));
    this.className = this.className.replace(/\b(warn|ok)\b/g,'').trim();
    this.classList.add(v > 0 ? 'ok' : 'warn');
  });
});
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>