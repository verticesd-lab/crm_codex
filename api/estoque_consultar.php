<?php
// api/estoque_consultar.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_pdo();

    // ---------------------------------------------------------
    // 1. SLUG DA EMPRESA (?empresa=minhaloja)
    // ---------------------------------------------------------
    $slug = isset($_GET['empresa']) ? trim($_GET['empresa']) : '';

    if ($slug === '') {
        http_response_code(400);
        echo json_encode(array(
            'ok'   => false,
            'erro' => 'Parâmetro "empresa" (slug) não informado.'
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, nome_fantasia FROM companies WHERE slug = ?');
    $stmt->execute(array($slug));
    $company = $stmt->fetch();

    if (!$company) {
        http_response_code(404);
        echo json_encode(array(
            'ok'   => false,
            'erro' => 'Empresa não encontrada para o slug informado.'
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $companyId = (int)$company['id'];

    // ---------------------------------------------------------
    // 2. LER MENSAGEM DO USUÁRIO
    //    - se ?debug=1&q=... -> usa GET (pra testar no navegador)
    //    - senão -> lê JSON do body
    // ---------------------------------------------------------
    $mensagem = '';
    $rawBody  = null;

    if (isset($_GET['debug']) && isset($_GET['q'])) {
        $mensagem = trim($_GET['q']);
    } else {
        $rawBody = file_get_contents('php://input');
        $data    = json_decode($rawBody, true);

        if (!is_array($data) || !isset($data['mensagem_usuario'])) {
            http_response_code(400);
            echo json_encode(array(
                'ok'       => false,
                'erro'     => 'Body inválido. Envie JSON com campo "mensagem_usuario".',
                'raw_body' => $rawBody,
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $mensagem = trim($data['mensagem_usuario']);
    }

    if ($mensagem === '') {
        http_response_code(400);
        echo json_encode(array(
            'ok'   => false,
            'erro' => 'Campo "mensagem_usuario" está vazio.'
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mensagemLower = strtolower($mensagem);

    // ---------------------------------------------------------
    // 3. BUSCAR PRODUTOS DA EMPRESA
    // ---------------------------------------------------------
    $stmt = $pdo->prepare('
        SELECT id, nome, categoria, preco
        FROM products
        WHERE company_id = ? AND ativo = 1
    ');
    $stmt->execute(array($companyId));
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$products) {
        echo json_encode(array(
            'ok'    => true,
            'msg'   => 'Nenhum produto cadastrado para esta empresa.',
            'itens' => array(),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------
    // 4. ACHAR PRODUTO QUE CASA COM A MENSAGEM
    // ---------------------------------------------------------
    $produtoEncontrado = null;

    foreach ($products as $p) {
        $nome      = strtolower($p['nome']);
        $categoria = strtolower(isset($p['categoria']) ? $p['categoria'] : '');

        if (strpos($mensagemLower, $nome) !== false ||
            ($categoria !== '' && strpos($mensagemLower, $categoria) !== false)
        ) {
            $produtoEncontrado = $p;
            break;
        }
    }

    if (!$produtoEncontrado) {
        echo json_encode(array(
            'ok'    => true,
            'msg'   => 'Não encontrei esse produto no catálogo.',
            'itens' => array(),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $productId = (int)$produtoEncontrado['id'];

    // ---------------------------------------------------------
    // 5. VARIANTES + ESTOQUE
    // ---------------------------------------------------------
    $stmt = $pdo->prepare('
        SELECT
            pv.size,
            COALESCE(sb.quantity, 0) AS quantity
        FROM product_variants pv
        LEFT JOIN stock_balances sb
            ON sb.product_variant_id = pv.id
           AND sb.location = "loja_fisica"
        WHERE pv.product_id = ?
        ORDER BY pv.size ASC
    ');
    $stmt->execute(array($productId));
    $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tamanhos = array();
    foreach ($variants as $v) {
        $qtd = (int)$v['quantity'];
        if ($qtd > 0) {
            $tamanhos[] = array(
                'tamanho'    => $v['size'],
                'quantidade' => $qtd,
            );
        }
    }

    if (empty($tamanhos)) {
        echo json_encode(array(
            'ok'      => true,
            'produto' => $produtoEncontrado['nome'],
            'msg'     => 'Tem o produto, mas está sem estoque nos tamanhos.',
            'tamanhos'=> array(),
        ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $linhas = array();
    foreach ($tamanhos as $t) {
        $linhas[] = '- ' . $t['tamanho'] . ' (' . $t['quantidade'] . ' unidades)';
    }

    $msgResposta = "Tenho " . $produtoEncontrado['nome'] . " disponível nos tamanhos:\n" .
        implode("\n", $linhas);

    echo json_encode(array(
        'ok'      => true,
        'empresa' => array(
            'slug'          => $slug,
            'nome_fantasia' => $company['nome_fantasia'],
        ),
        'mensagem_usuario' => $mensagem,
        'produto'  => $produtoEncontrado['nome'],
        'tamanhos' => $tamanhos,
        'resposta' => $msgResposta,
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'ok'       => false,
        'erro'     => 'excecao',
        'mensagem' => $e->getMessage(),
        'arquivo'  => $e->getFile(),
        'linha'    => $e->getLine(),
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
