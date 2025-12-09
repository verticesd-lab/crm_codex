<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$slug = $_GET['empresa'] ?? '';
if (!$slug) { echo 'Empresa não informada.'; exit; }

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ?');
$stmt->execute([$slug]);
$company = $stmt->fetch();
if (!$company) { echo 'Empresa não encontrada.'; exit; }

if (session_status() === PHP_SESSION_NONE) session_start();
$cartKey = 'cart_' . $company['slug'];
if (!isset($_SESSION[$cartKey])) $_SESSION[$cartKey] = [];

$items = [];
$total = 0;
if ($_SESSION[$cartKey]) {
    $ids = array_keys($_SESSION[$cartKey]);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders) AND ativo=1");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    foreach ($products as $p) {
        $qty = $_SESSION[$cartKey][$p['id']];
        $subtotal = $qty * $p['preco'];
        $items[] = ['produto' => $p, 'qty' => $qty, 'subtotal' => $subtotal];
        $total += $subtotal;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($items)) {
        echo 'Carrinho vazio.';
        exit;
    }
    $clienteNome = trim($_POST['cliente_nome'] ?? '');
    $clienteTelefone = trim($_POST['cliente_telefone'] ?? '');
    $obs = trim($_POST['observacoes'] ?? '');
    if (!$clienteNome || !$clienteTelefone) {
        $erro = 'Informe nome e telefone.';
    } else {
        // Procurar cliente existente
        $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE company_id=? AND (telefone_principal=? OR whatsapp=?) LIMIT 1');
        $clientStmt->execute([$company['id'], $clienteTelefone, $clienteTelefone]);
        $client = $clientStmt->fetch();
        if ($client) {
            $clientId = $client['id'];
        } else {
            $pdo->prepare('INSERT INTO clients (company_id, nome, telefone_principal, whatsapp, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
                ->execute([$company['id'], $clienteNome, $clienteTelefone, $clienteTelefone]);
            $clientId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO orders (company_id, client_id, origem, status, total, observacoes_cliente, created_at, updated_at) VALUES (?, ?, "loja", "novo", ?, ?, NOW(), NOW())')
            ->execute([$company['id'], $clientId, $total, $obs]);
        $orderId = (int)$pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantidade, preco_unitario, subtotal) VALUES (?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $p = $item['produto'];
            $itemStmt->execute([$orderId, $p['id'], $item['qty'], $p['preco'], $item['subtotal']]);
        }
        $pdo->prepare('UPDATE clients SET ltv_total = COALESCE(ltv_total,0) + ?, updated_at=NOW() WHERE id=?')->execute([$total, $clientId]);

        // mensagem WhatsApp
        $mensagem = "Olá! Tenho interesse no pedido #$orderId%0A";
        $mensagem .= "Nome: " . urlencode($clienteNome) . "%0A";
        $mensagem .= "Telefone: " . urlencode($clienteTelefone) . "%0A";
        $mensagem .= "Itens:%0A";
        foreach ($items as $item) {
            $mensagem .= "- " . urlencode($item['produto']['nome']) . " x" . $item['qty'] . " (" . number_format($item['produto']['preco'], 2, ',', '.') . ")%0A";
        }
        $mensagem .= "Total: " . number_format($total, 2, ',', '.') . "%0A";
        if ($obs) $mensagem .= "Obs: " . urlencode($obs) . "%0A";
        $_SESSION[$cartKey] = [];
        header('Location: https://api.whatsapp.com/send?phone=' . urlencode($company['whatsapp_principal']) . '&text=' . $mensagem);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?= sanitize($company['nome_fantasia']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.4/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-slate-100 min-h-screen">
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Checkout</h1>
            <a href="/loja.php?empresa=<?= urlencode($slug) ?>" class="text-indigo-600 hover:underline">Voltar à loja</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-2 bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
                <h2 class="text-xl font-semibold mb-4">Seus itens</h2>
                <?php if (!empty($items)): ?>
                    <div class="space-y-3">
                        <?php foreach ($items as $item): ?>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-medium"><?= sanitize($item['produto']['nome']) ?></p>
                                    <p class="text-sm text-slate-600"><?= $item['qty'] ?> x <?= format_currency($item['produto']['preco']) ?></p>
                                </div>
                                <p class="font-semibold"><?= format_currency($item['subtotal']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-slate-600">Seu carrinho está vazio.</p>
                <?php endif; ?>
                <p class="mt-4 text-lg font-semibold">Total: <?= format_currency($total) ?></p>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
                <h2 class="text-xl font-semibold mb-4">Seus dados</h2>
                <?php if (!empty($erro)): ?>
                    <div class="mb-3 p-3 rounded bg-red-50 text-red-700 border border-red-200"><?= sanitize($erro) ?></div>
                <?php endif; ?>
                <form method="POST" class="space-y-3">
                    <div>
                        <label class="block text-sm text-slate-600 mb-1">Nome</label>
                        <input name="cliente_nome" class="w-full rounded border-slate-300" required>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600 mb-1">Telefone/WhatsApp</label>
                        <input name="cliente_telefone" class="w-full rounded border-slate-300" required>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600 mb-1">Observações</label>
                        <textarea name="observacoes" class="w-full rounded border-slate-300" rows="3"></textarea>
                    </div>
                    <button class="w-full bg-emerald-600 text-white py-2 rounded hover:bg-emerald-700">Finalizar pelo WhatsApp</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
