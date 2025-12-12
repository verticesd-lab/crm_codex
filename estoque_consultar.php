<?php
// api/estoque_consultar.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // ---------------------------------------------------------------------
    // 1. Autenticação por Bearer Token (mesmo do Activepieces)
    // ---------------------------------------------------------------------
    $expectedToken = '123MkFZzH7fbNA9XaUGG5SlHYN1evAZVn';

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';

    if (stripos($authHeader, 'Bearer ') === 0) {
        $token = trim(substr($authHeader, 7));
    }

    if ($expectedToken !== '' && $token !== $expectedToken) {
        http_response_code(401);
        echo json_encode([
            'ok'     => false,
            'erro'   => 'Token inválido ou ausente',
            'codigo' => 'unauthorized',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------------
    // 2. Identificar empresa pelo slug (?empresa=minhaloja)
    // ---------------------------------------------------------------------
    $slug = $_GET['empresa'] ?? ($_GET['slug'] ?? '');

    if (!$slug) {
        http_response_code(400);
        echo json_encode([
            'ok'   => false,
            'erro' => 'Parâmetro "empresa" (slug) não informado.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = get_pdo();

    $stmt = $pdo->prepare('SELECT id, nome_fantasia FROM companies WHERE slug = ?');
    $stmt->execute([$slug]);
    $company = $stmt->fetch();

    if (!$company) {
        http_response_code(404);
        echo json_encode([
            'ok'   => false,
            'erro' => 'Empresa não encontrada para o slug informado.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $companyId = (int)$company['id'];

    // ---------------------------------------------------------------------
    // 3. Ler JSON do corpo da requisição
    // ---------------------------------------------------------------------
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'ok'       => false,
            'erro'     => 'Body inválido. Envie JSON.',
            'raw_body' => $rawBody,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $queryText = trim($data['mensagem_usuario'] ?? ($data['query'] ?? ''));

    if ($queryText === '') {
        http_response_code(400);
        echo json_encode([
            'ok'   => false,
            'erro' => 'Campo "mensagem_usuario" ou "query" é obrigatório.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------------
    // 4. Detectar tamanho na frase (número com 2 dígitos ou P/M/G/GG)
    // ---------------------------------------------------------------------
    $sizeFilter = null;

    // primeiro tenta número: 40, 41, 42...
    if (preg_match('/\b(\d{2})\b/', $queryText, $m)) {
        $sizeFilter = $m[1];
    }

    // se não achou número, tenta P, M, G, GG...
    if ($sizeFilter === null) {
        if (preg_match('/\b(PP|P|M|G|GG|XG|XGG)\b/i', $queryText, $m)) {
            $sizeFilter = strtoupper($m[1]);
        }
    }

    // termo de busca sem o tamanho
    $searchTerm = $queryText;
    if ($sizeFilter !== null) {
        $searchTerm = preg_replace(
            '/\b' . preg_quote($sizeFilter, '/') . '\b/i',
            '',
            $searchTerm,
            1
        );
    }
    $searchTerm = trim($searchTerm);

    // ---------------------------------------------------------------------
    // 5. Buscar produtos + variantes com estoque > 0
    // ---------------------------------------------------------------------
    $sql = "
        SELECT
            p.id              AS product_id,
            p.nome            AS product_name,
            p.descricao       AS product_description,
            p.preco           AS price,
            p.imagem          AS main_image,
            p.categoria       AS category,
            pv.id             AS variant_id,
            pv.size           AS size,
            COALESCE(b.quantity, 0) AS quantity
        FROM products p
        JOIN product_variants pv
            ON pv.product_id = p.id
           AND pv.active = 1
        LEFT JOIN stock_balances b
            ON b.product_variant_id = pv.id
           AND b.location = 'loja_fisica'
        WHERE
            p.ativo = 1
            AND p.company_id = :company_id
            AND COALESCE(b.quantity, 0) > 0
    ";

    $params = [
        ':company_id' => $companyId,
    ];

    if ($searchTerm !== '') {
        $sql .= "
            AND (
                p.nome        LIKE :term
                OR p.descricao LIKE :term
                OR p.categoria LIKE :term
            )
        ";
        $params[':term'] = '%' . $searchTerm . '%';
    }

    if ($sizeFilter !== null) {
        $sql .= " AND pv.size = :size";
        $params[':size'] = $sizeFilter;
    }

    $sql .= " ORDER BY p.nome ASC, pv.size ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'produto_id'   => (int)$row['product_id'],
            'produto'      => $row['product_name'],
            'categoria'    => $row['category'],
            'descricao'    => $row['product_description'],
            'preco'        => (float)$row['price'],
            'variant_id'   => (int)$row['variant_id'],
            'tamanho'      => $row['size'],
            'quantidade'   => (int)$row['quantity'],
            'imagem'       => $row['main_image'] ? image_url($row['main_image']) : null,
            'url_produto'  => BASE_URL . '/produto.php?empresa='
                             . urlencode($slug)
                             . '&id=' . (int)$row['product_id'],
        ];
    }

    echo json_encode([
        'ok'          => true,
        'empresa'     => [
            'slug'          => $slug,
            'nome_fantasia' => $company['nome_fantasia'],
        ],
        'consulta'    => [
            'texto_original' => $queryText,
            'termo_busca'    => $searchTerm,
            'tamanho'        => $sizeFilter,
        ],
        'total_itens' => count($items),
        'itens'       => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'       => false,
        'erro'     => 'excecao',
        'mensagem' => $e->getMessage(),
        'arquivo'  => $e->getFile(),
        'linha'    => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
