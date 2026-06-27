<?php
/**
 * products_imports_processor.php
 * ─────────────────────────────────────────────────────────────────────────
 * Funções de leitura e processamento de NF-e XML / CSV / XLSX extraídas
 * de products_imports.php para serem reusadas pelo api/v1/index.php.
 *
 * NÃO contém UI, só lógica. O arquivo products_imports.php continua
 * funcionando normalmente — só remove as funções dele e dá require neste.
 *
 * IMPORTANTE: as definições aqui são as MESMAS de products_imports.php.
 * Não duplique — mova as funções de lá pra cá e dê require_once de ambos
 * os lugares (products_imports.php e api/v1/index.php).
 */

if (!function_exists('ni_price')) {
    function ni_price($v): ?float {
        if ($v === null || $v === '') return null;
        $s = preg_replace('/[^0-9,\.]/', '', str_replace(['R$',' '], '', trim((string)$v)));
        if (substr_count($s,',') === 1 && substr_count($s,'.') === 0) $s = str_replace(',','.',$s);
        elseif (substr_count($s,',') >= 1 && substr_count($s,'.') >= 1) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        $f = (float)$s;
        return ($f > 0) ? $f : null;
    }
}

if (!function_exists('ni_int'))  { function ni_int($v): int  { return max(1,(int)preg_replace('/[^0-9]/','',(string)$v)); } }
if (!function_exists('ni_norm')) { function ni_norm($s): string { return trim(preg_replace('/\s+/',' ',(string)$s)); } }

if (!function_exists('ni_hdr')) {
    function ni_hdr($x): string {
        $x = preg_replace('/^\xEF\xBB\xBF/', '', trim(mb_strtolower((string)$x)));
        return str_replace(
            ['á','à','ã','â','é','ê','í','ó','ô','õ','ú','ü','ç'],
            ['a','a','a','a','e','e','i','o','o','o','u','u','c'],
            preg_replace('/\s+/',' ',$x)
        );
    }
}

if (!function_exists('ni_map_cols')) {
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
}

if (!function_exists('ni_read_xml_nfe')) {
    function ni_read_xml_nfe(string $path): array {
        $content = file_get_contents($path);
        if ($content === false) throw new Exception('Nao foi possivel ler o arquivo XML.');

        $content = preg_replace('/(<\/?)(\w+):/', '$1', $content);
        $content = preg_replace('/\s+xmlns[^"]*"[^"]*"/', '', $content);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if (!$xml) {
            $errs = libxml_get_errors();
            throw new Exception('XML invalido: ' . ($errs[0]->message ?? 'formato nao reconhecido'));
        }

        $itens = $xml->xpath('//det') ?: $xml->xpath('//NFe//det') ?: [];
        if (empty($itens)) $itens = $xml->xpath('//infNFe//det') ?: [];
        if (empty($itens)) {
            throw new Exception('Nenhum item encontrado no XML. Verifique se eh uma NF-e valida.');
        }

        $rows = [['nome','referencia','quantidade','preco_custo','unidade','ncm','descricao']];
        foreach ($itens as $det) {
            $prod = $det->prod ?? null;
            if (!$prod) continue;

            $nome     = ni_norm((string)($prod->xProd ?? ''));
            $ref      = ni_norm((string)($prod->cProd ?? ''));
            $qty      = (string)($prod->qCom  ?? $prod->qTrib ?? '1');
            $custo    = (string)($prod->vUnCom ?? $prod->vUnTrib ?? '0');
            $unidade  = ni_norm((string)($prod->uCom  ?? $prod->uTrib ?? ''));
            $ncm      = ni_norm((string)($prod->NCM   ?? ''));
            $infAdProd= ni_norm((string)($det->infAdProd ?? ''));

            if ($nome === '') continue;
            $rows[] = [$nome, $ref, $qty, $custo, $unidade, $ncm, $infAdProd];
        }

        if (count($rows) <= 1) {
            throw new Exception('XML lido mas nenhum produto encontrado.');
        }
        return $rows;
    }
}

if (!function_exists('ni_process_rows')) {
    function ni_process_rows(array $rows, PDO $pdo, int $importId, int $companyId): int {
        if (empty($rows)) throw new Exception('Arquivo vazio ou sem linhas reconheciveis.');

        $header = array_shift($rows);
        $map    = ni_map_cols($header);
        if ($map['nome'] === null) $map['nome'] = 0;

        $pdo->prepare('DELETE FROM product_import_items WHERE import_id=? AND company_id=?')
            ->execute([$importId, $companyId]);

        // Detecta se a tabela tem row_number (após migration) ou só row_index
        $hasRowNumber = false;
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM product_import_items')->fetchAll(PDO::FETCH_COLUMN);
            $hasRowNumber = in_array('row_number', $cols, true);
        } catch (Throwable $e) {}

        $rowField = $hasRowNumber ? '`row_number`' : '`row_index`';

        $ins = $pdo->prepare("
            INSERT INTO product_import_items
                (import_id, company_id, {$rowField},
                 raw_nome, raw_preco, raw_categoria, raw_descricao,
                 final_nome, final_preco, final_categoria, final_descricao,
                 referencia, preco_custo, quantidade, cor, tamanho,
                 status, error_message)
            VALUES
                (:import_id, :company_id, :row_num,
                 :raw_nome, :raw_preco, :raw_cat, :raw_desc,
                 :nome, :preco_venda, :cat, :desc,
                 :ref, :custo, :qty, :cor, :tam,
                 'draft', NULL)
        ");

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
}