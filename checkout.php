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
        $map[$p['id']] = $p;
    }

    foreach ($cart as $productId => $qty) {
        if (!isset($map[$productId])) {
            continue;
        }
        $produto  = $map[$productId];
        $qty      = (int)$qty;
        $subtotal = $qty * (float)$produto['preco'];
        $total   += $subtotal;

        $items[] = [
            'id'         => $produto['id'],
            'nome'       => $produto['nome'],
            'preco'      => (float)$produto['preco'],
            'quantidade' => $qty,
            'subtotal'   => $subtotal,
        ];
    }
}

// Se enviou o formulário, montar mensagem e redirecionar pro WhatsApp
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($items)) {
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

    // Deixa só dígitos (remove +, espaço, parênteses, etc)
    $whatsapp = preg_replace('/\D+/', '', $whatsapp);

    // DEBUG OPCIONAL: ver o texto que vai pro Whats na tela
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
    <title>Checkout - <?= sanitize($company['nome_fantasia']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Space Grotesk"', 'Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: {
                            500: '#7c3aed',
                            600: '#6d28d9',
                            700: '#5b21b6',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-950 text-white min-h-screen">
<div class="absolute inset-0 -z-10 overflow-hidden">
    <div class="absolute -top-24 -left-10 h-80 w-80 bg-brand-600/30 rounded-full blur-3xl"></div>
    <div class="absolute top-20 right-0 h-96 w-96 bg-emerald-500/20 rounded-full blur-3xl"></div>
</div>

<div class="max-w-5xl mx-auto px-4 py-8 space-y-6">
    <!-- Cabeçalho -->
    <header class="flex items-center justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-[0.2em] text-emerald-200/80">Checkout</p>
            <h1 class="text-3xl font-bold tracking-tight">
                Finalizar pedido
            </h1>
            <p class="text-sm text-slate-300 mt-1">
                Revise seus itens e envie o pedido para o WhatsApp da loja.
            </p>
        </div>
        <div class="text-right">
            <p class="text-xs text-slate-400">Loja</p>
            <p class="font-semibold"><?= sanitize($company['nome_fantasia']) ?></p>
            <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
               class="inline-flex text-sm text-emerald-300 hover:text-emerald-200 underline mt-1">
                ← Voltar à loja
            </a>
        </div>
    </header>

    <?php if (!empty($erro)): ?>
        <div class="bg-red-500/10 border border-red-500/40 text-red-100 px-4 py-3 rounded-xl text-sm">
            <?= sanitize($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 text-center space-y-3">
            <p class="text-lg font-semibold">Seu carrinho está vazio</p>
            <p class="text-sm text-slate-300">
                Adicione produtos na loja para fazer o pedido pelo WhatsApp.
            </p>
            <a href="<?= BASE_URL ?>/loja.php?empresa=<?= urlencode($slug) ?>"
               class="inline-flex items-center justify-center px-5 py-2 rounded-full bg-brand-600 hover:bg-brand-700 text-sm font-semibold">
                Voltar para a loja
            </a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-[1.4fr,1fr] gap-6 items-start">
            <!-- Resumo do pedido -->
            <section class="bg-white/5 border border-white/10 rounded-2xl p-5 space-y-4 shadow-xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Seus itens</h2>
                    <span class="text-xs px-3 py-1 rounded-full bg-emerald-500/15 text-emerald-200 border border-emerald-500/30">
                        <?= count($items) ?> produto(s)
                    </span>
                </div>

                <div class="divide-y divide-white/10">
                    <?php foreach ($items as $item): ?>
                        <div class="py-3 flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium">
                                    <?= sanitize($item['nome']) ?>
                                </p>
                                <p class="text-xs text-slate-300 mt-1">
                                    <?= (int)$item['quantidade'] ?> x <?= format_currency($item['preco']) ?>
                                </p>
                            </div>
                            <p class="font-semibold text-emerald-300 whitespace-nowrap">
                                <?= format_currency($item['subtotal']) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="border-t border-white/10 pt-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-300">Total do pedido</p>
                        <p class="text-2xl font-bold text-emerald-300">
                            <?= format_currency($total) ?>
                        </p>
                    </div>
                    <p class="text-xs text-slate-400 max-w-xs text-right">
                        O pagamento será combinado e finalizado diretamente com o atendente pelo WhatsApp.
                    </p>
                </div>
            </section>

            <!-- Formulário -->
            <section class="bg-white/5 border border-white/10 rounded-2xl p-5 space-y-4 shadow-xl">
                <h2 class="text-lg font-semibold">Seus dados</h2>
                <p class="text-sm text-slate-300">
                    Preencha seus dados para identificarmos seu pedido no WhatsApp.
                </p>

                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-200 mb-1">
                            Nome
                        </label>
                        <input type="text" name="nome"
                               class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                               placeholder="Seu nome completo">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-200 mb-1">
                            Telefone / WhatsApp
                        </label>
                        <input type="text" name="telefone"
                               class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                               placeholder="(00) 00000-0000">
                        <p class="text-[11px] text-slate-400 mt-1">
                            Usaremos esse número apenas para contato sobre este pedido.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-slate-200 mb-1">
                            Observações
                        </label>
                        <textarea name="observacoes" rows="3"
                                  class="w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                  placeholder="Ex.: tamanho, cor, ajuste de barra, forma de entrega, etc."></textarea>
                    </div>

                    <div class="pt-2 space-y-2">
                        <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-500 text-slate-900 font-semibold text-sm px-4 py-3 hover:bg-emerald-400 shadow-lg shadow-emerald-500/30">
                            Finalizar pelo WhatsApp
                        </button>
                        <p class="text-[11px] text-slate-400 text-center">
                            Ao clicar em “Finalizar pelo WhatsApp”, vamos abrir uma conversa com a loja já com o resumo do seu pedido.
                        </p>
                    </div>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
