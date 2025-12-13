<?php
// api/estoque_consultar.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

try {
    $pdo = get_pdo();

    // -----------------------------------------------------------------
    // 1. Empresa (slug via ?empresa=minhaloja)
    // -----------------------------------------------------------------
    $slug = $_GET['empresa'] ?? '';
    $slug = trim((string)$slug);

    if ($slug === '') {
        echo json_encode([
            'ok'   => false,
            'erro' => 'Empresa não informada. Use ?empresa=slug_da_loja.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, slug, nome_fantasia FROM companies WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        echo json_encode([
            'ok'   => false,
            'erro' => 'Empresa não encontrada para o slug informado.',
            'slug' => $slug,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $companyId = (int)$company['id'];

    // -----------------------------------------------------------------
    // 2. Mensagem do usuário
    //
    // - Produção (Activepieces): POST JSON { "mensagem_usuario": "..." }
    // - Debug no navegador: GET ?debug=1&q=...
    // -----------------------------------------------------------------
    $debug   = isset($_GET['debug']);
    $rawBody = file_get_contents('php://input') ?: '';
    $jsonBody = json_decode($rawBody, true);

    $mensagemUsuario = null;

    if (is_array($jsonBody) && isset($jsonBody['mensagem_usuario'])) {
        $mensagemUsuario = (string)$jsonBody['mensagem_usuario'];
    } elseif (isset($_GET['q'])) {
        // modo debug via navegador
        $mensagemUsuario = (string)$_GET['q'];
    }

    $mensagemUsuario = trim((string)$mensagemUsuario);

    if ($mensagemUsuario === '') {
        echo json_encode([
            'ok'       => false,
            'erro'     => 'Body inválido. Envie JSON com campo "mensagem_usuario".',
            'raw_body' => $rawBody,
            'exemplo'  => ['mensagem_usuario' => 'tenis new balance 41?'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // -----------------------------------------------------------------
    // 3. Encontrar o produto mais provável (match por palavras)
    // -----------------------------------------------------------------

    // Palavras “genéricas” que não ajudam a identificar produto
    $stopwords = [
        'tenis','tênis','camiseta','camisa','calça','bermuda','short','shorts','polo','blusa',
        'tamanho','tam','numero','número','num','cor',
        'tem','quero','gostaria','procuro','procurando',
        'de','do','da','um','uma','pra','para','no','na',
        'me','vc','você','voce','por','favor',
    ];

    // Função para transformar texto em lista de tokens relevantes
    $tokenize = function (string $texto) use ($stopwords): array {
        $t = mb_strtolower($texto, 'UTF-8');
        // troca tudo que não é letra/número por espaço
        $t = preg_replace('/[^a-z0-9áéíóúâêîôûãõç]+/u', ' ', $t);
        $parts = preg_split('/\s+/', $t);

        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (in_array($p, $stopwords, true)) {
                continue;
            }
            // ignora números puros (geralmente é só o tamanho: 40, 41, 42…)
            if (preg_match('/^\d+$/', $p)) {
                continue;
            }
            $tokens[] = $p;
        }

        // remove duplicadas
        return array_values(array_unique($tokens));
    };

    $tokensMensagem = $tokenize($mensagemUsuario);

    // Carrega todos os produtos ativos dessa empresa
    $stmt = $pdo->prepare("
        SELECT p.id, p.nome, p.categoria
        FROM products p
        WHERE
            p.company_id = ?
            AND p.ativo = 1
            AND p.nome IS NOT NULL
    ");
    $stmt->execute([$companyId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$products) {
        echo json_encode([
            'ok'   => false,
            'erro' => 'Nenhum produto ativo encontrado para esta empresa.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $produtoEscolhido = null;
    $bestScore        = 0;

    foreach ($products as $p) {
        $tokensProduto = $tokenize((string)$p['nome']);

        if (empty($tokensProduto) || empty($tokensMensagem)) {
            continue;
        }

        // score = quantas palavras do NOME do produto aparecem na mensagem
        $score = 0;
        foreach ($tokensProduto as $tp) {
            if (in_array($tp, $tokensMensagem, true)) {
                $score++;
            }
        }

        if ($score > $bestScore) {
            $bestScore        = $score;
            $produtoEscolhido = $p;
        }
    }

    // Se nenhuma palavra de nenhum produto bateu com a mensagem
    if (!$produtoEscolhido || $bestScore === 0) {
        echo json_encode([
            'ok'               => true,
            'empresa'          => [
                'slug'          => $company['slug'],
                'nome_fantasia' => $company['nome_fantasia'],
            ],
            'mensagem_usuario' => $mensagemUsuario,
            'produto'          => null,
            'tamanhos'         => [],
            'resposta'         => 'Não encontrei nenhum produto com esse nome ou relacionado a essa mensagem.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $productId   = (int)$produtoEscolhido['id'];
    $productName = (string)$produtoEscolhido['nome'];

    // -----------------------------------------------------------------
    // 4. Buscar variantes e estoque (location = loja_fisica)
    // -----------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT
            v.size AS tamanho,
            COALESCE(b.quantity, 0) AS quantidade
        FROM product_variants v
        LEFT JOIN stock_balances b
               ON b.product_variant_id = v.id
              AND b.location = 'loja_fisica'
        WHERE
            v.product_id = ?
            AND v.active = 1
        ORDER BY v.size
    ");
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $tamanhos = [];
    foreach ($rows as $row) {
        $qtd = (int)($row['quantidade'] ?? 0);
        if ($qtd <= 0) {
            continue;
        }
        $tamanhos[] = [
            'tamanho'    => (string)$row['tamanho'],
            'quantidade' => $qtd,
        ];
    }

    // -----------------------------------------------------------------
    // 5. Montar resposta em texto
    // -----------------------------------------------------------------
    if (empty($tamanhos)) {
        $resposta = "No momento não tenho estoque disponível para {$productName}.";
    } else {
        $linhas = [];
        foreach ($tamanhos as $t) {
            $suf = $t['quantidade'] === 1 ? 'unidade' : 'unidades';
            $linhas[] = "- {$t['tamanho']} ({$t['quantidade']} {$suf})";
        }

        $resposta  = "Tenho {$productName} disponível nos tamanhos:\n";
        $resposta .= implode("\n", $linhas);
    }

    // -----------------------------------------------------------------
    // 6. Resposta final
    // -----------------------------------------------------------------
    $out = [
        'ok'               => true,
        'empresa'          => [
            'slug'          => $company['slug'],
            'nome_fantasia' => $company['nome_fantasia'],
        ],
        'mensagem_usuario' => $mensagemUsuario,
        'produto'          => $productName,
        'tamanhos'         => $tamanhos,
        'resposta'         => $resposta,
    ];

    if ($debug) {
        $out['debug'] = [
            'raw_body' => $rawBody,
            'json'     => $jsonBody,
            'tokens_mensagem' => $tokensMensagem,
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;

} catch (Throwable $e) {
    // Nunca deixar estourar 500 pro Activepieces
    echo json_encode([
        'ok'   => false,
        'erro' => 'Exceção interna em estoque_consultar.php',
        'msg'  => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
