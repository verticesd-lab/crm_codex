<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// slug da empresa: via GET ou sessão
$slug = $_GET['empresa'] ?? ($_SESSION['company_slug'] ?? '');
if (!$slug) {
    echo 'Empresa não informada.';
    exit;
}

$pdo = get_pdo();

// Carrega empresa
$stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$stmt->execute([$slug]);
$company = $stmt->fetch();

if (!$company) {
    echo 'Empresa não encontrada.';
    exit;
}

// Carrinho por empresa
$cartKey = 'cart_' . $company['slug'];
$cart = $_SESSION[$cartKey] ?? [];

/**
 * ============================
 * AÇÃO: REMOVER ITEM DO CARRINHO
 * ============================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cart_action'] ?? '') === 'remove_item') {
    $pid = (int)($_POST['product_id'] ?? 0);

    if ($pid > 0 && isset($cart[$pid])) {
        unset($cart[$pid]);
        $_SESSION[$cartKey] = $cart;

        // se ficou vazio, limpa a chave
        if (empty($_SESSION[$cartKey])) {
            unset($_SESSION[$cartKey]);
        }
    }

    header('Location: ' . BASE_URL . '/checkout.php?empresa=' . urlencode($slug));
    exit;
}

$items = [];
$total = 0.0;

if (!empty($cart)) {
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_map('intval', $ids);
    array_unshift($params, (int)$company['id']);

    $sql = "SELECT * FROM products WHERE company_id = ? AND id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    $map = [];
    foreach ($products as $p) {
        $map[(int)$p['id']] = $p;
    }

    foreach ($cart as $productId => $qty) {
        $productId = (int)$productId;
        if (!isset($map[$productId])) {
            continue;
        }

        $produto  = $map[$productId];
        $qty      = (int)$qty;
        if ($qty <= 0) continue;

        $subtotal = $qty * (float)$produto['preco'];
        $total   += $subtotal;

        $items[] = [
            'id'         => (int)$produto['id'],
            'nome'       => (string)$produto['nome'],
            'preco'      => (float)$produto['preco'],
            'quantidade' => $qty,
            'subtotal'   => $subtotal,
        ];
    }
}

// Se enviou o formulário, montar mensagem e redirecionar pro WhatsApp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['cart_action']) && !empty($items)) {
    $nome = trim($_POST['nome'] ?? '');
    $tel  = trim($_POST['telefone'] ?? '');
    $obs  = trim($_POST['observacoes'] ?? '');

    $linhas = [];
    $linhas[] = "Novo pedido vindo da loja online " . $company['nome_fantasia'];
    $linhas[] = "";
    $linhas[] = "Itens:";

    foreach ($items as $item) {
        $linhas[] = "- {$item['quantidade']}x {$item['nome']} (" . format_currency($item['preco']) . ") = " . format_currency($item['subtotal']);
    }

    $linhas[] = "";
    $linhas[] = "Total: " . format_currency($total);
    $linhas[] = "";
    $linhas[] = "Dados do cliente:";

    if ($nome !== '') {
        $linhas[] = "Nome: {$nome}";
    }
    if ($tel !== '') {
        $linhas[] = "Telefone/WhatsApp: {$tel}";
    }
    if ($obs !== '') {
        $linhas[] = "";
        $linhas[] = "Observações:";
        $linhas[] = $obs;
    }

    $mensagem = implode("\n", $linhas);

    // --------- NORMALIZA O WHATSAPP DA EMPRESA ----------
    $whatsapp = trim($company['whatsapp_principal'] ?? '');

    // Se tiver um link inteiro salvo (começa com http), tenta extrair só o phone=...
    if ($whatsapp !== '' && strpos($whatsapp, 'http') === 0) {
        $parsed = parse_url($whatsapp);
        $query  = $parsed['query'] ?? '';
        parse_str($query, $qs);
        if (!empty($qs['phone'])) {
            $whatsapp = $qs['phone'];
        }
    }

    // Deixa só dígitos
    $whatsapp = preg_replace('/\D+/', '', $whatsapp);

    // DEBUG OPCIONAL
    if (isset($_GET['debug'])) {
        echo '<pre>' . htmlspecialchars($mensagem) . '</pre>';
        echo '<hr>';
        echo 'Enviando para o número: ' . htmlspecialchars($whatsapp);
        exit;
    }
    // -----------------------------------------------------

    if ($whatsapp) {
        $url = 'https://api.whatsapp.com/send?phone=' . $whatsapp .
            '&text=' . urlencode($mensagem);

        // limpa o carrinho depois de montar o pedido
        unset($_SESSION[$cartKey]);

        header('Location: ' . $url);
        exit;
    } else {
        $erro = 'WhatsApp da empresa não configurado corretamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Carrinho - <?= sanitize($company['nome_fantasia']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,500;0,600;0,700;1,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/loja-v2.css">
</head>
<body class="store-body">
    <?php $cartKey = "cart_" . $company["slug"]; include __DIR__ . "/views/partials/store_header_simple.php"; ?>

    <main class="store-main store-container">
        <a class="store-back" href="<?= sanitize(public_route_url("loja", $slug)) ?>">← Voltar para a loja</a>

        <div class="store-section-head">
            <div>
                <span class="store-section-kicker">Seu carrinho</span>
                <h1 class="store-section-title">Finalizar pedido</h1>
            </div>
            <p class="store-section-note">Revise seus itens e envie o pedido para o WhatsApp da loja.</p>
        </div>

        <?php if (!empty($erro)): ?>
            <div class="store-alert" role="alert"><?= sanitize($erro) ?></div>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <div class="store-empty">
                <strong>Seu carrinho está vazio</strong>
                <span>Adicione produtos na loja para fazer o pedido pelo WhatsApp.</span>
                <a class="store-button store-button-primary" href="<?= sanitize(public_route_url("loja", $slug)) ?>">Voltar para a loja</a>
            </div>
        <?php else: ?>
            <div class="store-cart-layout">
                <section class="store-panel" aria-labelledby="cart-items-title">
                    <div class="store-section-head">
                        <h2 id="cart-items-title">Seus itens</h2>
                        <p class="store-section-note"><?= count($items) ?> produto(s)</p>
                    </div>

                    <?php foreach ($items as $item): ?>
                        <div class="store-cart-item">
                            <div>
                                <p class="store-cart-item-name"><?= sanitize($item["nome"]) ?></p>
                                <p class="store-product-note"><?= (int)$item["quantidade"] ?> x <?= format_currency($item["preco"]) ?></p>
                                <form method="POST" data-confirm="Remover este produto do carrinho?">
                                    <input type="hidden" name="cart_action" value="remove_item">
                                    <input type="hidden" name="product_id" value="<?= (int)$item["id"] ?>">
                                    <button type="submit" class="store-cart-remove">Remover</button>
                                </form>
                            </div>
                            <p class="store-cart-item-price"><?= format_currency($item["subtotal"]) ?></p>
                        </div>
                    <?php endforeach; ?>

                    <div class="store-cart-total">
                        <div>
                            <span class="sizes-label">Total do pedido</span>
                            <p class="store-product-price"><?= format_currency($total) ?></p>
                        </div>
                        <p class="store-product-note">O pagamento será combinado e finalizado diretamente com o atendente pelo WhatsApp.</p>
                    </div>
                </section>

                <section class="store-panel" aria-labelledby="cart-form-title">
                    <h2 id="cart-form-title">Seus dados</h2>
                    <p class="store-product-note">Preencha seus dados para identificarmos seu pedido no WhatsApp.</p>

                    <form method="POST" class="store-filter-form">
                        <div class="store-field">
                            <label for="cart-nome">Nome</label>
                            <input class="store-input" id="cart-nome" type="text" name="nome" placeholder="Seu nome completo" autocomplete="name">
                        </div>
                        <div class="store-field">
                            <label for="cart-telefone">Telefone / WhatsApp</label>
                            <input class="store-input" id="cart-telefone" type="tel" name="telefone" placeholder="(00) 00000-0000" autocomplete="tel">
                            <span class="store-product-note">Usaremos esse número apenas para contato sobre este pedido.</span>
                        </div>
                        <div class="store-field">
                            <label for="cart-obs">Observações</label>
                            <textarea class="store-input" id="cart-obs" name="observacoes" rows="3" placeholder="Ex.: tamanho, cor, ajuste de barra, forma de entrega, etc."></textarea>
                        </div>
                        <button type="submit" class="store-button store-button-whatsapp">Finalizar pelo WhatsApp</button>
                        <p class="store-product-note">Ao finalizar, abrimos uma conversa com a loja já com o resumo do seu pedido.</p>
                    </form>
                </section>
            </div>
        <?php endif; ?>
    </main>
    <script>
        document.querySelectorAll("form[data-confirm]").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                if (!window.confirm(form.dataset.confirm)) event.preventDefault();
            });
        });
    </script>

<?php $chatHideOrder = true; include __DIR__ . '/views/partials/store_chat.php'; ?>
</body>
</html>
