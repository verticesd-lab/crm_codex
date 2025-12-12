<?php
// estoque_consultar.php  (NA RAIZ DO PROJETO)

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = get_pdo();

    // -----------------------------------------------------------------
    // 1. Slug da empresa: ?empresa=minhaloja
    // -----------------------------------------------------------------
    $slug = $_GET['empresa'] ?? '';

    if (!$slug) {
        http_response_code(400);
        echo json_encode([
            'ok'   => false,
            'erro' => 'Parâmetro "empresa" não informado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, nome_fantasia FROM companies WHERE slug = ?");
    $stmt->execute([$slug]);
    $company = $stmt->fetch();

    if (!$company) {
        http_response_code(404);
        echo json_encode([
            'ok'   => false,
            'erro' => 'Empresa não encontrada para esse slug.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $companyId = (int)$company['id'];

    // -----------------------------------------------------------------
    // 2. Ler JSON do corpo
    // -----------------------------------------------------------------
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!is_array($data) || empty($data['mensagem_usuario'])) {
        http_response_code(400);
        echo json_encode([
            'ok'       => false,
            'erro'     => 'Envie JSON com o campo "mensagem_usuario".',
            'raw_body' => $rawBody,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mensagem = mb_strtolower(trim($data['mensagem_usuario']), 'UTF-8');

    // -----------------------------------------------------------------
    // 3. Buscar produto mencionado na frase
    // -----------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT id, nome, categoria, preco
        FROM products
        WHERE company_id = ? AND ativo = 1
    ");
    $stmt->execute([$companyId]);
    $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $produtoEncontrado = null;

    foreach ($produtos as $p) {
        $nome      = mb_strtolower($p['nome'], 'UTF-8');
        $categoria = mb_strtolower($p['categoria'] ?? '', 'UTF-8');

        if (strpos($mensagem, $nome) !== false || ($categoria && strpos($mensagem, $categoria) !== false)) {
            $produtoEncontrado = $p;
            break;
        }
    }

    if (!$produtoEncontrado) {
        echo json_encode([
            'ok'       => true,
            'resposta' => 'Não encontrei esse produto no estoque.',
            'itens'    => []
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // -----------------------------------------------------------------
    // 4. Variantes + estoque
    // -----------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT 
            pv.size,
            COALESCE(sb.quantity, 0) AS quantidade
        FROM product_variants pv
        LEFT JOIN stock_balances sb
            ON sb.product_variant_id = pv.id
           AND sb.location = 'loja_fisica'
        WHERE pv.product_id = ?
        ORDER BY pv.size
    ");
    $stmt->execute([$produtoEncontrado['id']]);
    $variantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $disponiveis = array_filter($variantes, fn($v) => (int)$v['quantidade'] > 0);

    if (!$disponiveis) {
        echo json_encode([
            'ok'       => true,
            'resposta' => "Tem {$produtoEncontrado['nome']}, mas está sem estoque nos tamanhos.",
            'itens'    => []
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $tamanhos = [];
    foreach ($disponiveis as $v) {
        $tamanhos[] = [
            'tamanho'    => $v['size'],
            'quantidade' => (int)$v['quantidade'],
        ];
    }

    $frases = array_map(function ($t) {
        return "{$t['tamanho']} ({$t['quantidade']} un.)";
    }, $tamanhos);

    $resposta = "Tenho {$produtoEncontrado['nome']} disponível nos tamanhos:\n- " .
        implode("\n- ", $frases);

    echo json_encode([
        'ok'        => true,
        'empresa'   => [
            'slug'          => $slug,
            'nome_fantasia' => $company['nome_fantasia'],
        ],
        'produto'   => $produtoEncontrado['nome'],
        'tamanhos'  => $tamanhos,
        'resposta'  => $resposta,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'       => false,
        'erro'     => 'excecao',
        'mensagem' => $e->getMessage(),
        'arquivo'  => $e->getFile(),
        'linha'    => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
