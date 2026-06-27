<?php
/**
 * products_helpers.php — Funções compartilhadas entre products.php e api/v1
 * ─────────────────────────────────────────────────────────────────────────
 * Centraliza lógica de:
 *   - Geração de SKU automático
 *   - Sincronização de variantes (extraído de products.php)
 *   - Detecção de duplicidade
 *   - Atualização de estoque agregado em products.estoque
 *   - Log de ações do agente Hermes
 *
 * Inclua em qualquer arquivo que mexe com produtos:
 *   require_once __DIR__ . '/products_helpers.php';
 */

if (!function_exists('hermes_slugify')) {
    /**
     * Slugify simples: remove acentos, espaços, caracteres especiais.
     * Usado para gerar SKU legível.
     */
    function hermes_slugify(string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        // Remove acentos
        $s = str_replace(
            ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç','ñ',
             'Á','À','Ã','Â','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Õ','Ô','Ö','Ú','Ù','Û','Ü','Ç','Ñ'],
            ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n',
             'A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N'],
            $s
        );
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9]+/', '', $s);
        return $s;
    }
}

if (!function_exists('hermes_generate_sku')) {
    /**
     * Gera SKU determinístico no padrão:
     *   {REF}-{COR}-{SIZE}      se houver referência
     *   P{product_id}-{COR}-{SIZE}  se não houver
     *
     * Exemplo: "LCT123-BRANCO-M" ou "P247-PRETO-41"
     */
    function hermes_generate_sku(int $productId, ?string $referencia, ?string $cor, ?string $size): string {
        $ref = hermes_slugify((string)$referencia);
        $col = hermes_slugify((string)$cor);
        $sz  = hermes_slugify((string)$size);

        $prefix = $ref !== '' ? $ref : ('P' . $productId);
        $parts  = array_filter([$prefix, $col, $sz], fn($p) => $p !== '');
        return implode('-', $parts);
    }
}

if (!function_exists('hermes_sync_variants')) {
    /**
     * Sincroniza variantes de um produto com base em sizes (CSV) e cor.
     *
     * IMPORTANTE: este helper preserva variantes existentes com saldo > 0,
     * ao contrário do sync_variants() original em products.php que apaga tudo.
     * Isso evita perda de histórico de stock_movements quando o dono salva
     * o produto várias vezes.
     *
     * @param PDO    $pdo
     * @param int    $productId
     * @param string $sizesCsv "P,M,G,GG"
     * @param string $cor      Cor do produto (vai pra todas as variantes deste save)
     * @param string $referencia
     * @return array{created:int,kept:int,deactivated:int} Estatística
     */
    function hermes_sync_variants(
        PDO $pdo,
        int $productId,
        string $sizesCsv,
        string $cor = '',
        string $referencia = ''
    ): array {
        $sizes = array_filter(array_map('trim', explode(',', $sizesCsv)));
        $stats = ['created' => 0, 'kept' => 0, 'deactivated' => 0];

        // Variantes existentes deste produto
        $stmt = $pdo->prepare('
            SELECT pv.id, pv.color, pv.size, pv.active,
                   COALESCE(SUM(sb.quantity), 0) AS total_stock
            FROM product_variants pv
            LEFT JOIN stock_balances sb ON sb.product_variant_id = pv.id
            WHERE pv.product_id = ?
            GROUP BY pv.id
        ');
        $stmt->execute([$productId]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Index por (cor, size) pra lookup rápido
        $existingMap = [];
        foreach ($existing as $v) {
            $key = strtolower(($v['color'] ?? '') . '|' . ($v['size'] ?? ''));
            $existingMap[$key] = $v;
        }

        // Pra cada tamanho desejado: ou já existe e mantém, ou cria
        $wantedKeys = [];
        foreach ($sizes as $sz) {
            $key = strtolower($cor . '|' . $sz);
            $wantedKeys[$key] = true;

            if (isset($existingMap[$key])) {
                // Já existe — reativa se estava desativado
                $v = $existingMap[$key];
                if ((int)$v['active'] === 0) {
                    $pdo->prepare('UPDATE product_variants SET active=1, updated_at=NOW() WHERE id=?')
                        ->execute([$v['id']]);
                }
                $stats['kept']++;
            } else {
                // Cria nova variante
                $sku = hermes_generate_sku($productId, $referencia, $cor, $sz);
                // SKU UNIQUE: tenta inserir, se colidir adiciona sufixo aleatório
                $tries = 0;
                $finalSku = $sku;
                while ($tries < 5) {
                    try {
                        $pdo->prepare('
                            INSERT INTO product_variants
                                (product_id, color, size, sku, active, created_at, updated_at)
                            VALUES (?,?,?,?,1,NOW(),NOW())
                        ')->execute([$productId, $cor ?: null, $sz, $finalSku]);
                        $vid = (int)$pdo->lastInsertId();
                        $pdo->prepare('
                            INSERT INTO stock_balances (product_variant_id, location, quantity, updated_at)
                            VALUES (?, "loja_fisica", 0, NOW())
                        ')->execute([$vid]);
                        $stats['created']++;
                        break;
                    } catch (PDOException $e) {
                        if ($e->errorInfo[1] == 1062) { // duplicate key
                            $tries++;
                            $finalSku = $sku . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
                        } else {
                            throw $e;
                        }
                    }
                }
            }
        }

        // Desativa variantes que não estão mais na lista — MAS preserva se tiver saldo
        foreach ($existing as $v) {
            $key = strtolower(($v['color'] ?? '') . '|' . ($v['size'] ?? ''));
            if (!isset($wantedKeys[$key]) && (int)$v['active'] === 1) {
                // Tem saldo? Mantém ativa (mas tagueia como "fora de linha" via reason)
                if ((int)$v['total_stock'] > 0) {
                    continue; // não mexe — tem peça em estoque
                }
                $pdo->prepare('UPDATE product_variants SET active=0, updated_at=NOW() WHERE id=?')
                    ->execute([$v['id']]);
                $stats['deactivated']++;
            }
        }

        return $stats;
    }
}

if (!function_exists('hermes_recalc_product_stock')) {
    /**
     * Recalcula products.estoque = SUM(variantes ativas).
     * Mantém retrocompat com código antigo que lê direto de products.estoque.
     */
    function hermes_recalc_product_stock(PDO $pdo, int $productId): int {
        $stmt = $pdo->prepare('
            SELECT COALESCE(SUM(sb.quantity), 0) AS total
            FROM product_variants pv
            JOIN stock_balances sb ON sb.product_variant_id = pv.id
            WHERE pv.product_id = ? AND pv.active = 1
        ');
        $stmt->execute([$productId]);
        $total = (int)$stmt->fetchColumn();
        $pdo->prepare('UPDATE products SET estoque=?, updated_at=NOW() WHERE id=?')
            ->execute([$total, $productId]);
        return $total;
    }
}

if (!function_exists('hermes_find_duplicate_product')) {
    /**
     * Procura produto duplicado por referência OU nome+cor.
     * Retorna array do produto ou null.
     */
    function hermes_find_duplicate_product(
        PDO $pdo,
        int $companyId,
        string $nome,
        string $referencia = '',
        string $cor = ''
    ): ?array {
        // 1) Por referência (mais confiável)
        if ($referencia !== '') {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE company_id=? AND referencia=? LIMIT 1');
            $stmt->execute([$companyId, $referencia]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
        // 2) Por nome exato + cor (case insensitive)
        if ($nome !== '') {
            if ($cor !== '') {
                $stmt = $pdo->prepare('
                    SELECT * FROM products
                    WHERE company_id=? AND LOWER(nome)=LOWER(?) AND LOWER(COALESCE(cor,""))=LOWER(?)
                    LIMIT 1
                ');
                $stmt->execute([$companyId, $nome, $cor]);
            } else {
                $stmt = $pdo->prepare('
                    SELECT * FROM products
                    WHERE company_id=? AND LOWER(nome)=LOWER(?) AND (cor IS NULL OR cor="")
                    LIMIT 1
                ');
                $stmt->execute([$companyId, $nome]);
            }
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
        return null;
    }
}

if (!function_exists('hermes_log_action')) {
    /**
     * Loga uma ação do agente Hermes na tabela agent_actions.
     * Nunca lança exceção — logging não pode quebrar o request.
     *
     * @param array $ctx [
     *   'company_id'         => int,
     *   'tool_name'          => string,        // ex: cadastrar_produto
     *   'endpoint'           => string|null,   // ex: /api/v1/cadastrar_produto
     *   'payload'            => array|null,    // input
     *   'response'           => array|null,    // output
     *   'http_status'        => int|null,
     *   'success'            => bool,
     *   'error_message'      => string|null,
     *   'duration_ms'        => int|null,
     *   'model_used'         => string|null,
     *   'telegram_chat_id'   => int|null,
     *   'telegram_user_id'   => int|null,
     *   'telegram_message_id'=> int|null,
     *   'agent_name'         => string,        // default 'hermes'
     * ]
     */
    function hermes_log_action(PDO $pdo, array $ctx): void {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO agent_actions (
                    company_id, agent_name,
                    telegram_chat_id, telegram_user_id, telegram_message_id,
                    tool_name, endpoint,
                    payload_json, response_json,
                    http_status, success, error_message,
                    duration_ms, model_used,
                    created_at
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ');
            $stmt->execute([
                (int)($ctx['company_id'] ?? 0),
                (string)($ctx['agent_name'] ?? 'hermes'),
                isset($ctx['telegram_chat_id'])    ? (int)$ctx['telegram_chat_id']    : null,
                isset($ctx['telegram_user_id'])    ? (int)$ctx['telegram_user_id']    : null,
                isset($ctx['telegram_message_id']) ? (int)$ctx['telegram_message_id'] : null,
                (string)($ctx['tool_name'] ?? 'unknown'),
                isset($ctx['endpoint']) ? (string)$ctx['endpoint'] : null,
                isset($ctx['payload'])  ? json_encode($ctx['payload'],  JSON_UNESCAPED_UNICODE) : null,
                isset($ctx['response']) ? json_encode($ctx['response'], JSON_UNESCAPED_UNICODE) : null,
                isset($ctx['http_status']) ? (int)$ctx['http_status'] : null,
                !empty($ctx['success']) ? 1 : 0,
                isset($ctx['error_message']) ? (string)$ctx['error_message'] : null,
                isset($ctx['duration_ms']) ? (int)$ctx['duration_ms'] : null,
                isset($ctx['model_used'])  ? (string)$ctx['model_used']  : null,
            ]);
        } catch (Throwable $e) {
            // Silencioso de propósito — logging nunca pode quebrar o fluxo principal
            error_log('hermes_log_action failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('hermes_buscar_cliente')) {
    /**
     * Busca cliente por telefone OU nome.
     * Atualiza a função original buscar_cliente() do api/v1 pra olhar
     * em whatsapp E telefone_principal.
     */
    function hermes_buscar_cliente(PDO $pdo, int $companyId, string $tel = '', string $nome = '') {
        if ($tel !== '') {
            $digits = preg_replace('/\D/', '', $tel);
            if (strlen($digits) <= 11 && substr($digits, 0, 2) !== '55') {
                $digits = '55' . $digits;
            }
            $last8 = substr($digits, -8);
            $stmt = $pdo->prepare("
                SELECT id, nome, whatsapp AS telefone, telefone_principal, email
                FROM clients
                WHERE company_id=?
                  AND (
                    REGEXP_REPLACE(COALESCE(whatsapp,''),           '[^0-9]','') = ?
                 OR REGEXP_REPLACE(COALESCE(whatsapp,''),           '[^0-9]','') = ?
                 OR REGEXP_REPLACE(COALESCE(telefone_principal,''), '[^0-9]','') = ?
                 OR REGEXP_REPLACE(COALESCE(telefone_principal,''), '[^0-9]','') = ?
                 OR RIGHT(REGEXP_REPLACE(COALESCE(whatsapp,''),           '[^0-9]',''), 8) = ?
                 OR RIGHT(REGEXP_REPLACE(COALESCE(telefone_principal,''), '[^0-9]',''), 8) = ?
                  )
                LIMIT 1
            ");
            $stmt->execute([
                $companyId,
                $digits, substr($digits, 2),
                $digits, substr($digits, 2),
                $last8,  $last8
            ]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
        if ($nome !== '') {
            $stmt = $pdo->prepare("
                SELECT id, nome, whatsapp AS telefone, telefone_principal, email
                FROM clients
                WHERE company_id=? AND nome LIKE ?
                LIMIT 5
            ");
            $stmt->execute([$companyId, '%' . trim($nome) . '%']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) return $rows[0];
            if (count($rows) > 1)   return $rows; // múltiplos
        }
        return null;
    }
}