<?php
// api/estoque_consultar.php
ini_set('display_errors', 0);
error_reporting(EALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

try {
    $pdo = get_pdo();

    // 1) Empresa (slug na query string)
    $slug = $_GET['empresa'] ?? '';
    $slug = trim($slug);

    if ($slug === '') {
        echo json_encode([
            'ok'   => false,
            'erro' => 'Slug da empresa não informado. Use ?empresa=seuslug',
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

    // 2) Modo DEBUG via GET (o que você já testou no navegador)
    $debug           = isset($_GET['debug']) ? (int)$_GET['debug'] : 0;
    $mensagemUsuario = '';

    if ($debug === 1) {
        $mensagemUsuario = trim($_GET['q'] ?? '');
    } else {
        // 3) Modo NORMAL: lê JSON do POST
        $rawBody = file_get_contents('php://input');
        $data    = json_decode($rawBody, true);

        if (!is_array($data)) {
            echo json_encode([
                'ok'       => false,
                'erro'     => 'Body inválido. Envie JSON com campo "mensagem_usuario".',
                'raw_body' => $rawBody,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        $mensagemUsuario = trim($data['mensagem_usuario'] ?? '');

        if ($mensagemUsuario === '') {
            echo json_encode([
                'ok'       => false,
                'erro'     => 'Campo "mensagem_usuario" vazio.',
                'raw_body' => $rawBody,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }
    }

    // ---------------------------------------------------------
    // 4) Escolher o produto com "melhor match" com a mensagem
    // ---------------------------------------------------------

    // palavras que não ajudam a identificar o produto
    $stopwords = [
        'tenis','tênis','camiseta','camisa','calça','bermuda','short','polo','blusa',
        'tamanho','tam','numero','número','num','cor','cor:',
        'tem','quero','gostaria','procuro','procurando','de','do','da','um','uma',
        'pra','para','no','na','ai','aí','me','vc','você','voce','por','favor',
        '?','!','.',','
    ];

    // função auxiliar pra quebrar texto em tokens úteis
    $tokenize = function (string $texto) use ($stopwords): array {
        $t = mb_strtolower($texto, 'UTF-8');
        // troca qualquer coisa que não é letra/número por espaço
        $t = preg_replace('/[^a-z0-9áéíóúâêîôûãõç]+/u', ' ', $t);
        $parts = array_filter(array_map('trim', explode(' ', $t)));

        $tokens = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            if (in_array($p, $stopwords, true)) continue;
            // ignora números puros (muitas vezes é só o tamanho 40, 41...)
            if (preg_match('/^\d+$/', $p)) continue;
            $tokens[] = $p;
        }
        return array_values(array_unique($tokens));
    };

    $tokensMensagem = $tokenize($mensagemUsuario);

    // carrega produtos ativos
    $stmt = $pdo->prepare('
        SELECT id, nome
        FROM products
        WHERE company_id = ?
          AND ativo = 1
          AND nome IS NOT NULL
        ORDER BY nome ASC
    ');
    $stmt->execute([$company['id']]);
    $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$allProducts) {
        echo json_encode([
            'ok'   => false,
            'erro' => 'Nenhum produto ativo cadastrado para esta empresa.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $produtoEscolhido = null;
    $bestScore        = -1;

    foreach ($allProducts as $p) {
        $tokensProduto = $tokenize($p['nome']);

        if (empty($tokensProduto) || empty($tokensMensagem)) {
            continue;
        }

        // conta quantas palavras do produto aparecem na mensagem
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

    // se nada casou (score 0 pra todo mundo), fallback pro primeiro produto
    if (!$produtoEscolhido) {
        $produtoEscolhido = $allProducts[0];
    }

    $productId   = (int)$produtoEscolhido['id'];
    $nomeProduto = $produtoEscolhido['nome'];

    // ---------------------------------------------------------
    // 5) Buscar tamanhos e estoque (variants + stock_balances)
    // ---------------------------------------------------------
    $stmt = $pdo->prepare('
        SELECT
            pv.size AS tamanho,
            COALESCE(b.quantity, 0) AS quantidade
        FROM product_variants pv
        LEFT JOIN stock_balances b
               ON b.product_variant_id = pv.id
              AND b.location = "loja_fisica"
        WHERE pv.product_id = ?
        ORDER BY pv.size
    ');
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tamanhos = [];
    foreach ($rows as $row) {
        $qtd = (int)$row['quantidade'];
        if ($qtd > 0) {
            $tamanhos[] = [
                'tamanho'    => (string)$row['tamanho'],
                'quantidade' => $qtd,
            ];
        }
    }

    // ---------------------------------------------------------
    // 6) Monta resposta em texto
    // ---------------------------------------------------------
    if (empty($tamanhos)) {
        $resposta = "No momento não tenho {$nomeProduto} disponível no estoque físico.";
    } else {
        $partes = [];
        foreach ($tamanhos as $t) {
            $partes[] = $t['tamanho'] . ' (' . $t['quantidade'] . ' unidade' . ($t['quantidade'] > 1 ? 's' : '') . ')';
        }

        $lista = implode("\n- ", $partes);

        $resposta  = "Tenho {$nomeProduto} disponível nos tamanhos:\n";
        $resposta .= "- " . $lista;
    }

    // ---------------------------------------------------------
    // 7) Retorno final
    // ---------------------------------------------------------
    echo json_encode([
        'ok'               => true,
        'empresa'          => [
            'slug'          => $company['slug'],
            'nome_fantasia' => $company['nome_fantasia'],
        ],
        'mensagem_usuario' => $mensagemUsuario,
        'produto'          => $nomeProduto,
        'tamanhos'         => $tamanhos,
        'resposta'         => $resposta,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(200); // evita 500 pro Activepieces
    echo json_encode([
        'ok'   => false,
        'erro' => 'excecao',
        'msg'  => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
