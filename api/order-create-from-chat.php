<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

checkApiToken();

$pdo = get_pdo();
$input = array_merge($_POST, getJsonInput());

$companyId = (int)($input['company_id'] ?? 0);
$clientId = (int)($input['client_id'] ?? 0);
$origem = trim($input['origem'] ?? 'ia');
$canal = trim($input['canal'] ?? '');
$itemsInput = $input['itens'] ?? ($input['items'] ?? []);

if (!$companyId || !$clientId) {
    apiJsonError('Campos obrigatorios: company_id e client_id');
}

if (!is_array($itemsInput) || empty($itemsInput)) {
    apiJsonError('Envie itens do pedido');
}

$normalizedItems = [];
foreach ($itemsInput as $item) {
    if (!is_array($item)) {
        continue;
    }
    $productId = (int)($item['product_id'] ?? 0);
    $quantidade = (int)($item['quantidade'] ?? ($item['quantity'] ?? 1));
    if ($productId <= 0) {
        continue;
    }
    $normalizedItems[] = [
        'product_id' => $productId,
        'quantidade' => max(1, $quantidade),
    ];
}

if (empty($normalizedItems)) {
    apiJsonError('Nenhum item valido informado');
}

$origemValor = $origem !== '' ? substr($origem, 0, 60) : 'ia';
$canalValor = $canal !== '' ? substr($canal, 0, 40) : 'whatsapp';

try {
    $clientCheck = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
    $clientCheck->execute([$clientId, $companyId]);
    if (!$clientCheck->fetch()) {
        apiJsonError('Cliente nao pertence a empresa', 404);
    }

    $productIds = array_values(array_unique(array_column($normalizedItems, 'product_id')));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $productsStmt = $pdo->prepare("SELECT id, company_id, nome, preco FROM products WHERE company_id = ? AND ativo = 1 AND id IN ($placeholders)");
    $productsStmt->execute(array_merge([$companyId], $productIds));
    $products = [];
    foreach ($productsStmt->fetchAll() as $product) {
        $products[$product['id']] = $product;
    }

    $missing = array_diff($productIds, array_keys($products));
    if (!empty($missing)) {
        apiJsonError('Produto(s) nao encontrado(s) para esta empresa: ' . implode(',', $missing), 404);
    }

    $total = 0;
    $orderItemsData = [];
    $resumoItens = [];

    foreach ($normalizedItems as $item) {
        $product = $products[$item['product_id']];
        $unitPrice = (float)$product['preco'];
        $subtotal = $unitPrice * $item['quantidade'];
        $total += $subtotal;
        $orderItemsData[] = [
            'product_id' => $product['id'],
            'quantidade' => $item['quantidade'],
            'preco_unitario' => $unitPrice,
            'subtotal' => $subtotal,
            'nome' => $product['nome'],
        ];
        $resumoItens[] = $item['quantidade'] . 'x ' . $product['nome'];
    }

    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare('INSERT INTO orders (company_id, client_id, origem, status, total, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $orderStmt->execute([$companyId, $clientId, $origemValor, 'novo', $total]);
    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantidade, preco_unitario, subtotal) VALUES (?, ?, ?, ?, ?)');
    foreach ($orderItemsData as $item) {
        $itemStmt->execute([$orderId, $item['product_id'], $item['quantidade'], $item['preco_unitario'], $item['subtotal']]);
    }

    $pdo->prepare('UPDATE clients SET ltv_total = COALESCE(ltv_total, 0) + ?, ultimo_atendimento_em = NOW(), updated_at = NOW() WHERE id = ?')->execute([$total, $clientId]);

    $interactionResumo = 'Itens: ' . implode(', ', $resumoItens) . '. Total: ' . number_format((float)$total, 2, '.', '');
    $interactionStmt = $pdo->prepare('INSERT INTO interactions (company_id, client_id, canal, origem, titulo, resumo, atendente, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $interactionStmt->execute([$companyId, $clientId, $canalValor, 'ia', 'Pedido criado pela IA', $interactionResumo, 'IA']);

    $pdo->commit();

    $orderFetch = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $orderFetch->execute([$orderId]);
    $orderData = $orderFetch->fetch();

    $itemsFetch = $pdo->prepare('SELECT oi.id, oi.product_id, oi.quantidade, oi.preco_unitario, oi.subtotal, p.nome FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $itemsFetch->execute([$orderId]);

    apiJsonResponse(true, [
        'order' => $orderData,
        'items' => $itemsFetch->fetchAll(),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    apiJsonError('Erro ao criar pedido via atendimento da IA', 500);
}
