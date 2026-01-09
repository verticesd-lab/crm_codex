<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$companyId = current_company_id();
if (!$companyId) {
    $stmt = $pdo->query('SELECT id FROM companies ORDER BY id ASC LIMIT 1');
    $row = $stmt->fetch();
    if ($row) {
        $companyId = (int)$row['id'];
        $_SESSION['company_id'] = $companyId;
    } else {
        http_response_code(400);
        echo 'Nenhuma empresa configurada.';
        exit;
    }
}

/** =========================
 * Helpers
 * ========================= */

function normalize_price($value): ?float {
    if ($value === null) return null;
    $v = trim((string)$value);
    if ($v === '') return null;

    // Remove R$ e espaços
    $v = str_ireplace(['R$', ' '], '', $v);

    // "1.234,56" -> "1234.56"
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (strpos($v, ',') !== false && strpos($v, '.') === false) {
        $v = str_replace(',', '.', $v);
    }

    $v = preg_replace('/[^0-9.]/', '', $v);
    if ($v === '' || !is_numeric($v)) return null;

    $f = (float)$v;
    if ($f <= 0) return null;
    return $f;
}

function smart_title($s): string {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function ensure_upload_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function detect_csv_delimiter(string $filePath): string {
    $sample = file_get_contents($filePath, false, null, 0, 4096);
    if ($sample === false) return ';';
    $delims = [',',';','\t','|'];
    $best = ';';
    $bestCount = -1;
    foreach ($delims as $d) {
        $count = substr_count($sample, $d);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $d;
        }
    }
    return $best;
}

function normalize_header_cell($x): string {
    $x = (string)$x;
    // remove BOM UTF-8 (Excel)
    $x = preg_replace('/^\xEF\xBB\xBF/', '', $x);

    $x = trim(mb_strtolower($x));
    $x = preg_replace('/\s+/', ' ', $x);
    $x = str_replace(['á','à','ã','â','ä'], 'a', $x);
    $x = str_replace(['é','ê','ë'], 'e', $x);
    $x = str_replace(['í','î','ï'], 'i', $x);
    $x = str_replace(['ó','ô','õ','ö'], 'o', $x);
    $x = str_replace(['ú','û','ü'], 'u', $x);
    $x = str_replace(['ç'], 'c', $x);
    return $x;
}

function map_columns(array $header): array {
    $h = array_map('normalize_header_cell', $header);

    $pick = function(array $aliases) use ($h) {
        foreach ($h as $i => $name) {
            foreach ($aliases as $a) {
                $a = normalize_header_cell($a);
                if ($name === $a) return $i;
                if ($a !== '' && strpos($name, $a) !== false) return $i;
            }
        }
        return null;
    };

    return [
        'nome'      => $pick(['nome','produto','item','descricao do produto','titulo','title']),
        'preco'     => $pick(['preco','preço','valor','valor venda','preco venda','price']),
        'categoria' => $pick(['categoria','grupo','secao','seção','departamento','category']),
        'descricao' => $pick(['descricao','descrição','detalhes','observacao','observação','description']),
    ];
}

function paginate(int $page, int $perPage): array {
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;
    return [$page, $perPage, $offset];
}

function url_import(string $qs = ''): string {
    $base = rtrim((string)BASE_URL, '/');
    $self = basename(__FILE__); // <- evita 404 (seja products_imports.php ou outro nome)
    return $base . '/' . $self . ($qs ? ('?' . $qs) : '');
}

/** =========================
 * Estado / Flash
 * ========================= */

$action = $_GET['action'] ?? 'list';
$flashSuccess = get_flash('success') ?? null;
$flashError = get_flash('error') ?? null;

/** =========================
 * Upload (cria lote)
 * ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_upload'])) {
    if (empty($_FILES['file']['name'])) {
        flash('error', 'Selecione um arquivo.');
        redirect(url_import('action=create'));
    }

    $name = $_FILES['file']['name'];
    $tmp  = $_FILES['file']['tmp_name'];
    $err  = $_FILES['file']['error'];

    if ($err !== UPLOAD_ERR_OK) {
        flash('error', 'Falha no upload. Código: ' . $err);
        redirect(url_import('action=create'));
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['csv','xlsx','pdf'];
    if (!in_array($ext, $allowed, true)) {
        flash('error', 'Formato não suportado. Use CSV (recomendado).');
        redirect(url_import('action=create'));
    }

    $uploadDir = __DIR__ . '/uploads/imports';
    ensure_upload_dir($uploadDir);

    $safeBase = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', pathinfo($name, PATHINFO_FILENAME));
    $finalName = $safeBase . '_' . date('Ymd_His') . '.' . $ext;
    $destPath = $uploadDir . '/' . $finalName;

    if (!move_uploaded_file($tmp, $destPath)) {
        flash('error', 'Não foi possível salvar o arquivo no servidor.');
        redirect(url_import('action=create'));
    }

    $stmt = $pdo->prepare('
        INSERT INTO product_imports (company_id, original_filename, stored_path, file_ext, status)
        VALUES (?, ?, ?, ?, "uploaded")
    ');
    $stmt->execute([$companyId, $name, 'uploads/imports/' . $finalName, $ext]);
    $importId = (int)$pdo->lastInsertId();

    flash('success', 'Arquivo enviado! Agora clique em PROCESSAR para gerar os rascunhos.');
    redirect(url_import('action=view&id=' . $importId));
}

/** =========================
 * Processar (gera itens)
 * ========================= */
if ($action === 'process' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];

    $stmt = $pdo->prepare('SELECT * FROM product_imports WHERE id = ? AND company_id = ?');
    $stmt->execute([$importId, $companyId]);
    $imp = $stmt->fetch();

    if (!$imp) {
        flash('error', 'Importação não encontrada.');
        redirect(url_import());
    }

    $filePath = __DIR__ . '/' . $imp['stored_path'];
    $ext = strtolower((string)$imp['file_ext']);
    $total = 0;

    try {
        // limpa e processa em transação
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM product_import_items WHERE import_id = ? AND company_id = ?')
            ->execute([$importId, $companyId]);

        if ($ext === 'csv') {
            if (!file_exists($filePath)) throw new Exception('Arquivo não existe no servidor.');

            $delimiter = detect_csv_delimiter($filePath);
            $fh = fopen($filePath, 'r');
            if (!$fh) throw new Exception('Não foi possível abrir o CSV.');

            $header = fgetcsv($fh, 0, $delimiter);
            if (!$header || count($header) < 1) {
                fclose($fh);
                throw new Exception('CSV vazio ou inválido.');
            }

            $map = map_columns($header);
            if ($map['nome'] === null) $map['nome'] = 0;

            $rowNumber = 1; // header = 1
            while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                $rowNumber++;
                if (count($row) === 1 && trim((string)$row[0]) === '') continue;

                $rawNome  = $map['nome'] !== null ? ($row[$map['nome']] ?? '') : '';
                $rawPreco = $map['preco'] !== null ? ($row[$map['preco']] ?? null) : null;
                $rawCat   = $map['categoria'] !== null ? ($row[$map['categoria']] ?? '') : '';
                $rawDesc  = $map['descricao'] !== null ? ($row[$map['descricao']] ?? '') : '';

                $finalNome  = smart_title($rawNome);
                $finalPreco = normalize_price($rawPreco);
                $finalCat   = trim((string)$rawCat);
                $finalDesc  = trim((string)$rawDesc);

                if ($finalNome === '') continue;

                $status = 'draft';
                $errMsg = null;

                // INSERT correto (sem quebrar SQL)
                $stmtIns = $pdo->prepare('
                    INSERT INTO product_import_items
                        (import_id, company_id, row_number, raw_nome, raw_preco, raw_categoria, raw_descricao,
                         final_nome, final_preco, final_categoria, final_descricao, status, error_message)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $stmtIns->execute([
                    $importId,
                    $companyId,
                    $rowNumber,
                    (string)$rawNome,
                    (string)$rawPreco,      // raw como texto
                    (string)$rawCat,
                    (string)$rawDesc,
                    $finalNome,
                    $finalPreco,            // final como float (ou null)
                    $finalCat,
                    $finalDesc,
                    $status,
                    $errMsg
                ]);

                $total++;
            }

            fclose($fh);

            $pdo->prepare('UPDATE product_imports SET status="processed", total_rows=? WHERE id=? AND company_id=?')
                ->execute([$total, $importId, $companyId]);

            $pdo->commit();

            flash('success', "Processado! Foram gerados {$total} rascunhos.");
            redirect(url_import('action=view&id=' . $importId));
        }

        // XLSX e PDF (mantém “bloqueado” no MVP)
        $pdo->prepare('UPDATE product_imports SET status="failed" WHERE id=? AND company_id=?')
            ->execute([$importId, $companyId]);
        $pdo->commit();

        if ($ext === 'xlsx') {
            flash('error', 'XLSX (Excel) ainda não é suportado neste MVP (sem libs). Exporte para CSV e reenvie.');
        } else {
            flash('error', 'PDF ainda não está ativo neste MVP simples (sem OCR/sem libs). Use CSV por enquanto.');
        }
        redirect(url_import('action=view&id=' . $importId));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        $pdo->prepare('UPDATE product_imports SET status="failed" WHERE id=? AND company_id=?')
            ->execute([$importId, $companyId]);

        flash('error', 'Falha ao processar: ' . $e->getMessage());
        redirect(url_import('action=view&id=' . $importId));
    }
}

/** =========================
 * Salvar item (inline)
 * ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $itemId = (int)($_POST['item_id'] ?? 0);

    $nome = smart_title($_POST['final_nome'] ?? '');
    $preco = normalize_price($_POST['final_preco'] ?? null);
    $cat = trim((string)($_POST['final_categoria'] ?? ''));
    $desc = trim((string)($_POST['final_descricao'] ?? ''));
    $approved = isset($_POST['approved']) ? 1 : 0;

    $status = $approved ? 'approved' : 'draft';

    $stmt = $pdo->prepare('
        UPDATE product_import_items
        SET final_nome=?, final_preco=?, final_categoria=?, final_descricao=?, status=?, updated_at=NOW()
        WHERE id=? AND company_id=?
    ');
    $stmt->execute([$nome, $preco, $cat, $desc, $status, $itemId, $companyId]);

    flash('success', 'Item atualizado.');
    $back = $_POST['back'] ?? url_import();
    redirect($back);
}

/** =========================
 * Aprovar tudo
 * ========================= */
if ($action === 'approve_all' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];
    $pdo->prepare('
        UPDATE product_import_items
        SET status="approved"
        WHERE import_id=? AND company_id=? AND status IN ("draft","error")
    ')->execute([$importId, $companyId]);

    flash('success', 'Todos os itens foram aprovados.');
    redirect(url_import('action=view&id=' . $importId));
}

/** =========================
 * Publicar aprovados -> products
 * ========================= */
if ($action === 'publish' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];

    $stmt = $pdo->prepare('
        SELECT * FROM product_import_items
        WHERE import_id=? AND company_id=? AND status="approved"
        ORDER BY id ASC
    ');
    $stmt->execute([$importId, $companyId]);
    $items = $stmt->fetchAll();

    if (!$items) {
        flash('error', 'Nenhum item aprovado para publicar.');
        redirect(url_import('action=view&id=' . $importId));
    }

    $published = 0;

    foreach ($items as $it) {
        $nome = trim((string)$it['final_nome']);
        $preco = (float)($it['final_preco'] ?? 0);
        $categoria = trim((string)$it['final_categoria']);
        $descricao = trim((string)$it['final_descricao']);

        if ($nome === '' || $preco <= 0) {
            $pdo->prepare('
                UPDATE product_import_items
                SET status="error", error_message="Nome vazio ou preço inválido"
                WHERE id=? AND company_id=?
            ')->execute([(int)$it['id'], $companyId]);
            continue;
        }

        $stmtIns = $pdo->prepare('
            INSERT INTO products (company_id, nome, descricao, preco, categoria, sizes, imagem, ativo, destaque, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, "", "", 0, 0, NOW(), NOW())
        ');
        $stmtIns->execute([$companyId, $nome, $descricao, $preco, $categoria]);

        $pdo->prepare('
            UPDATE product_import_items
            SET status="published", updated_at=NOW()
            WHERE id=? AND company_id=?
        ')->execute([(int)$it['id'], $companyId]);

        $published++;
    }

    flash('success', "Publicação concluída! Produtos criados como RASCUNHO (ativo=0): {$published}");
    redirect(rtrim((string)BASE_URL, '/') . '/products.php');
}

/** =========================
 * Excluir lote
 * ========================= */
if ($action === 'delete' && isset($_GET['id'])) {
    $importId = (int)$_GET['id'];

    $stmt = $pdo->prepare('SELECT stored_path FROM product_imports WHERE id=? AND company_id=?');
    $stmt->execute([$importId, $companyId]);
    $row = $stmt->fetch();

    $pdo->prepare('DELETE FROM product_imports WHERE id=? AND company_id=?')->execute([$importId, $companyId]);

    if ($row && !empty($row['stored_path'])) {
        $path = __DIR__ . '/' . $row['stored_path'];
        if (is_file($path)) @unlink($path);
    }

    flash('success', 'Importação removida.');
    redirect(url_import());
}

include __DIR__ . '/views/partials/header.php';
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Cadastro Inteligente</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">
                Importe produtos via <b>CSV</b> (recomendado). XLSX/PDF ficam para versão robusta.
            </p>
        </div>
        <a href="<?= sanitize(url_import('action=create')) ?>"
           class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
            + Novo upload
        </a>
    </div>

    <?php if ($flashSuccess): ?>
        <div class="p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
            <?= sanitize($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="p-3 rounded bg-red-50 text-red-700 border border-red-200">
            <?= sanitize($flashError) ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'create'): ?>
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm space-y-4">
            <h2 class="text-lg font-semibold">Enviar arquivo</h2>
            <div class="text-sm text-slate-600 dark:text-slate-400">
                <p><b>Formato recomendado:</b> CSV com colunas como: <code>nome, preco, categoria, descricao</code>.</p>
                <p class="mt-1">Excel (XLSX) e PDF serão suportados na versão robusta.</p>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-3">
                <input type="hidden" name="do_upload" value="1">
                <input type="file" name="file" accept=".csv,.xlsx,.pdf" class="text-sm">
                <div class="flex justify-between">
                    <a href="<?= sanitize(url_import()) ?>" class="text-sm text-slate-600 dark:text-slate-300 hover:underline">
                        Voltar
                    </a>
                    <button class="px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                        Enviar
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <?php
            $stmt = $pdo->prepare('
                SELECT *
                FROM product_imports
                WHERE company_id=?
                ORDER BY id DESC
                LIMIT 30
            ');
            $stmt->execute([$companyId]);
            $imports = $stmt->fetchAll();
        ?>
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm">
            <h2 class="text-lg font-semibold mb-4">Últimas importações</h2>

            <?php if (!$imports): ?>
                <p class="text-sm text-slate-500">Nenhuma importação ainda.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="py-2">ID</th>
                                <th>Arquivo</th>
                                <th>Tipo</th>
                                <th>Status</th>
                                <th>Linhas</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <?php foreach ($imports as $imp): ?>
                                <tr>
                                    <td class="py-2"><?= (int)$imp['id'] ?></td>
                                    <td><?= sanitize($imp['original_filename']) ?></td>
                                    <td><?= sanitize(strtoupper($imp['file_ext'])) ?></td>
                                    <td>
                                        <span class="text-[11px] px-2 py-0.5 rounded-full
                                            <?= $imp['status']==='processed' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : '' ?>
                                            <?= $imp['status']==='uploaded' ? 'bg-slate-100 text-slate-700 border border-slate-200' : '' ?>
                                            <?= $imp['status']==='failed' ? 'bg-red-50 text-red-700 border border-red-100' : '' ?>
                                        ">
                                            <?= sanitize($imp['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= (int)$imp['total_rows'] ?></td>
                                    <td class="text-right">
                                        <a class="text-indigo-600 hover:underline"
                                           href="<?= sanitize(url_import('action=view&id=' . (int)$imp['id'])) ?>">
                                            Abrir
                                        </a>
                                        <span class="mx-2 text-slate-300">|</span>
                                        <a class="text-red-600 hover:underline"
                                           href="<?= sanitize(url_import('action=delete&id=' . (int)$imp['id'])) ?>"
                                           onclick="return confirm('Remover esta importação?');">
                                            Remover
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'view' && isset($_GET['id'])): ?>
        <?php
            $importId = (int)$_GET['id'];
            $stmt = $pdo->prepare('SELECT * FROM product_imports WHERE id=? AND company_id=?');
            $stmt->execute([$importId, $companyId]);
            $imp = $stmt->fetch();

            if (!$imp) {
                echo '<div class="p-3 rounded bg-red-50 text-red-700 border border-red-200">Importação não encontrada.</div>';
            } else {
                $page = (int)($_GET['page'] ?? 1);
                $perPage = (int)($_GET['per_page'] ?? 30);
                [$page, $perPage, $offset] = paginate($page, $perPage);

                $stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM product_import_items WHERE import_id=? AND company_id=?');
                $stmtCnt->execute([$importId, $companyId]);
                $countItems = (int)$stmtCnt->fetchColumn();
                $totalPages = (int)ceil(max(1, $countItems) / $perPage);

                $stmtItems = $pdo->prepare('
                    SELECT *
                    FROM product_import_items
                    WHERE import_id=? AND company_id=?
                    ORDER BY id ASC
                    LIMIT ? OFFSET ?
                ');
                $stmtItems->bindValue(1, $importId, PDO::PARAM_INT);
                $stmtItems->bindValue(2, $companyId, PDO::PARAM_INT);
                $stmtItems->bindValue(3, $perPage, PDO::PARAM_INT);
                $stmtItems->bindValue(4, $offset, PDO::PARAM_INT);
                $stmtItems->execute();
                $items = $stmtItems->fetchAll();

                $stmtStats = $pdo->prepare('
                    SELECT status, COUNT(*) as c
                    FROM product_import_items
                    WHERE import_id=? AND company_id=?
                    GROUP BY status
                ');
                $stmtStats->execute([$importId, $companyId]);
                $stats = [];
                foreach ($stmtStats->fetchAll() as $s) $stats[$s['status']] = (int)$s['c'];
            }
        ?>

        <?php if (!empty($imp)): ?>
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-6 shadow-sm space-y-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Lote #<?= (int)$imp['id'] ?> — <?= sanitize($imp['original_filename']) ?></h2>
                        <p class="text-sm text-slate-600 dark:text-slate-400">
                            Status: <b><?= sanitize($imp['status']) ?></b> • Tipo: <b><?= sanitize(strtoupper($imp['file_ext'])) ?></b> • Linhas: <b><?= (int)$imp['total_rows'] ?></b>
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a class="px-3 py-2 rounded border border-slate-200 dark:border-slate-800 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
                           href="<?= sanitize(url_import()) ?>">
                            Voltar
                        </a>

                        <a class="px-3 py-2 rounded bg-slate-900 text-white text-sm hover:bg-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600"
                           href="<?= sanitize(rtrim((string)BASE_URL,'/') . '/' . ltrim((string)$imp['stored_path'], '/')) ?>"
                           target="_blank">
                            Baixar arquivo
                        </a>

                        <a class="px-3 py-2 rounded bg-indigo-600 text-white text-sm hover:bg-indigo-700"
                           href="<?= sanitize(url_import('action=process&id=' . (int)$imp['id'])) ?>"
                           onclick="return confirm('Processar e gerar rascunhos? (isso recria os itens)');">
                            Processar
                        </a>

                        <?php if (($imp['status'] ?? '') === 'processed'): ?>
                            <a class="px-3 py-2 rounded bg-amber-500 text-slate-900 text-sm font-semibold hover:bg-amber-400"
                               href="<?= sanitize(url_import('action=approve_all&id=' . (int)$imp['id'])) ?>">
                                Aprovar todos
                            </a>

                            <a class="px-3 py-2 rounded bg-emerald-500 text-slate-900 text-sm font-semibold hover:bg-emerald-400"
                               href="<?= sanitize(url_import('action=publish&id=' . (int)$imp['id'])) ?>"
                               onclick="return confirm('Publicar itens aprovados? (cria produtos como rascunho: ativo=0)');">
                                Publicar aprovados
                            </a>
                        <?php endif; ?>

                        <a class="px-3 py-2 rounded bg-red-600 text-white text-sm hover:bg-red-700"
                           href="<?= sanitize(url_import('action=delete&id=' . (int)$imp['id'])) ?>"
                           onclick="return confirm('Remover este lote?');">
                            Remover lote
                        </a>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="px-2 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200">draft: <?= (int)($stats['draft'] ?? 0) ?></span>
                    <span class="px-2 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100">approved: <?= (int)($stats['approved'] ?? 0) ?></span>
                    <span class="px-2 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">published: <?= (int)($stats['published'] ?? 0) ?></span>
                    <span class="px-2 py-1 rounded-full bg-red-50 text-red-700 border border-red-100">error: <?= (int)($stats['error'] ?? 0) ?></span>
                </div>

                <div class="flex items-center justify-between">
                    <div class="text-sm text-slate-600 dark:text-slate-400">
                        Itens: <b><?= (int)$countItems ?></b> • Página <b><?= (int)$page ?></b> / <b><?= (int)$totalPages ?></b>
                    </div>

                    <form method="GET" class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="action" value="view">
                        <input type="hidden" name="id" value="<?= (int)$importId ?>">
                        <input type="hidden" name="page" value="1">
                        <label class="text-slate-600 dark:text-slate-300">Por página:</label>
                        <select name="per_page" class="rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700" onchange="this.form.submit()">
                            <?php foreach ([30,50,60,100] as $pp): ?>
                                <option value="<?= $pp ?>" <?= $perPage===$pp ? 'selected' : '' ?>><?= $pp ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <?php if (empty($items)): ?>
                    <div class="p-3 rounded bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800">
                        Nenhum item ainda. Clique em <b>Processar</b>.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-3">
                        <?php foreach ($items as $it): ?>
                            <form method="POST" class="border border-slate-200 dark:border-slate-800 rounded-xl p-4 bg-slate-50 dark:bg-slate-900/40">
                                <input type="hidden" name="save_item" value="1">
                                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                                <input type="hidden" name="back" value="<?= sanitize(url_import('action=view&id=' . $importId . '&page=' . $page . '&per_page=' . $perPage)) ?>">

                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                                    <div class="text-sm">
                                        <b>#<?= (int)($it['row_number'] ?? 0) ?></b>
                                        <span class="ml-2 text-[11px] px-2 py-0.5 rounded-full
                                            <?= $it['status']==='draft' ? 'bg-slate-200/60 text-slate-700' : '' ?>
                                            <?= $it['status']==='approved' ? 'bg-amber-100 text-amber-800' : '' ?>
                                            <?= $it['status']==='published' ? 'bg-emerald-100 text-emerald-800' : '' ?>
                                            <?= $it['status']==='error' ? 'bg-red-100 text-red-800' : '' ?>
                                        "><?= sanitize($it['status']) ?></span>

                                        <?php if (!empty($it['error_message'])): ?>
                                            <span class="ml-2 text-xs text-red-600"><?= sanitize($it['error_message']) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <label class="inline-flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="approved" value="1" <?= ($it['status']==='approved') ? 'checked' : '' ?>>
                                        Aprovar
                                    </label>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                                    <div>
                                        <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Nome</label>
                                        <input name="final_nome" value="<?= sanitize($it['final_nome'] ?? '') ?>"
                                               class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Preço</label>
                                            <input name="final_preco" value="<?= sanitize($it['final_preco'] ?? '') ?>"
                                                   placeholder="Ex: 199,90"
                                                   class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                                        </div>
                                        <div>
                                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Categoria</label>
                                            <input name="final_categoria" value="<?= sanitize($it['final_categoria'] ?? '') ?>"
                                                   class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700">
                                        </div>
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Descrição</label>
                                        <textarea name="final_descricao" rows="3"
                                                  class="w-full rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 text-sm"><?= sanitize($it['final_descricao'] ?? '') ?></textarea>
                                    </div>
                                </div>

                                <div class="flex justify-end mt-3">
                                    <button class="px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                                        Salvar item
                                    </button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex items-center justify-between mt-4">
                        <div class="text-sm text-slate-600 dark:text-slate-400">
                            Página <?= (int)$page ?> / <?= (int)$totalPages ?>
                        </div>
                        <div class="flex gap-2">
                            <?php
                                $prev = max(1, $page - 1);
                                $next = min($totalPages, $page + 1);
                            ?>
                            <a class="px-3 py-2 rounded border border-slate-200 dark:border-slate-800 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 <?= $page<=1 ? 'opacity-50 pointer-events-none' : '' ?>"
                               href="<?= sanitize(url_import('action=view&id=' . (int)$importId . '&page=' . (int)$prev . '&per_page=' . (int)$perPage)) ?>">
                                Anterior
                            </a>
                            <a class="px-3 py-2 rounded border border-slate-200 dark:border-slate-800 text-sm hover:bg-slate-50 dark:hover:bg-slate-800 <?= $page>=$totalPages ? 'opacity-50 pointer-events-none' : '' ?>"
                               href="<?= sanitize(url_import('action=view&id=' . (int)$importId . '&page=' . (int)$next . '&per_page=' . (int)$perPage)) ?>">
                                Próxima
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
