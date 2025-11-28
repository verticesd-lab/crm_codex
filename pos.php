<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

// Fetch clients and products for selectors
$clients = $pdo->prepare('SELECT id, nome, telefone_principal, whatsapp FROM clients WHERE company_id=? ORDER BY nome');
$clients->execute([$companyId]);
$clients = $clients->fetchAll();

$products = $pdo->prepare('SELECT id, nome, preco FROM products WHERE company_id=? AND ativo=1 ORDER BY nome');
$products->execute([$companyId]);
$products = $products->fetchAll();

$flashError = $flashSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $newClientName = trim($_POST['new_client_name'] ?? '');
    $newClientPhone = trim($_POST['new_client_phone'] ?? '');
    $noClient = isset($_POST['no_client']);
    $itemsJson = $_POST['items'] ?? '[]';
    $items = json_decode($itemsJson, true);
    if (!$items || !is_array($items)) {
        $flashError = 'Adicione ao menos um item.';
    } else {
        if (!$clientId && $newClientName) {
            $pdo->prepare('INSERT INTO clients (company_id, nome, telefone_principal, whatsapp, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
                ->execute([$companyId, $newClientName, $newClientPhone, $newClientPhone]);
            $clientId = (int)$pdo->lastInsertId();
        }
        if (!$clientId && !$noClient) {
            $flashError = 'Selecione um cliente, cadastre ou marque venda sem cadastro.';
        } else {
            $total = 0;
            foreach ($items as $it) {
                $total += ($it['qty'] ?? 0) * ($it['price'] ?? 0);
            }
            $clientValue = $clientId ?: null;
            $pdo->prepare('INSERT INTO orders (company_id, client_id, origem, status, total, created_at, updated_at) VALUES (?, ?, "pdv", "concluido", ?, NOW(), NOW())')
                ->execute([$companyId, $clientValue, $total]);
            $orderId = (int)$pdo->lastInsertId();
            $stmtItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantidade, preco_unitario, subtotal) VALUES (?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $stmtItem->execute([$orderId, $it['id'], $it['qty'], $it['price'], $it['qty'] * $it['price']]);
            }
            if ($clientId) {
                $pdo->prepare('UPDATE clients SET ltv_total = COALESCE(ltv_total,0) + ?, updated_at=NOW() WHERE id=?')->execute([$total, $clientId]);
            }
            log_action($pdo, $companyId, $_SESSION['user_id'], 'pdv_venda', 'Pedido #' . $orderId . ' total ' . $total);
            $flashSuccess = 'Venda registrada no PDV (#' . $orderId . ').';
        }
    }
}

include __DIR__ . '/views/partials/header.php';
if ($flashSuccess) echo '<div data-flash class="mb-4 p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">' . sanitize($flashSuccess) . '</div>';
if ($flashError) echo '<div data-flash class="mb-4 p-3 rounded bg-red-50 text-red-700 border border-red-200">' . sanitize($flashError) . '</div>';
?>
<div class="bg-white border border-slate-200 dark:bg-slate-900 dark:border-slate-800 rounded-xl p-6 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold">PDV Rápido</h1>
            <p class="text-sm text-slate-600 dark:text-slate-400">Registre vendas presenciais e atualize KPIs.</p>
        </div>
        <a href="/orders.php" class="text-sm text-indigo-600 hover:underline">Ver pedidos</a>
    </div>
    <form method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-4" id="pos-form">
        <div class="space-y-4 lg:col-span-2">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Cliente existente</label>
                        <select name="client_id" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                            <option value="">Selecione</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= sanitize($c['nome']) ?> <?= $c['telefone_principal'] ? ' - '.sanitize($c['telefone_principal']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Ou novo cliente</label>
                        <input name="new_client_name" placeholder="Nome" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700 mb-2">
                        <input name="new_client_phone" placeholder="Telefone/WhatsApp" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                    </div>
                    <div class="md:col-span-2">
                        <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" name="no_client" id="no_client" class="rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                            Registrar como "cliente final" (sem cadastro)
                        </label>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Use esta opção para vendas rápidas sem nome.</p>
                    </div>
                </div>

            <div class="p-4 rounded border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                    <div class="md:col-span-7">
                        <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Buscar produto (nome ou iniciais)</label>
                        <input id="product-search" type="text" placeholder="Digite para buscar..." class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700" autocomplete="off">
                        <div id="product-suggestions" class="mt-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded shadow-sm max-h-52 overflow-y-auto hidden text-sm"></div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm text-slate-600 dark:text-slate-300 mb-1">Qtd</label>
                        <input type="number" id="qty-input" value="1" min="1" class="w-full rounded border-slate-300 dark:bg-slate-800 dark:border-slate-700">
                    </div>
                    <div class="md:col-span-3 flex items-end">
                        <button type="button" id="add-item" class="w-full bg-indigo-600 text-white rounded px-4 py-2 hover:bg-indigo-700">Adicionar</button>
                    </div>
                </div>

                <div class="mt-5 overflow-hidden border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200">
                            <tr>
                                <th class="px-3 py-2 text-left">Produto</th>
                                <th class="px-3 py-2 text-left">Qtd</th>
                                <th class="px-3 py-2 text-left">Unit</th>
                                <th class="px-3 py-2 text-left">Subtotal</th>
                                <th class="px-3 py-2 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="items-table"></tbody>
                    </table>
                </div>
                <p class="mt-3 text-right text-base font-semibold">Total: <span id="total-display">R$ 0,00</span></p>
            </div>
            <input type="hidden" name="items" id="items-input">
        </div>
        <div class="space-y-3">
            <button class="w-full bg-emerald-600 text-white py-3 rounded hover:bg-emerald-700">Finalizar PDV</button>
            <p class="text-xs text-slate-500 dark:text-slate-400">Origem marcada como PDV e status concluído. Os KPIs serão atualizados no dashboard.</p>
        </div>
    </form>
</div>
<script>
    const products = <?= json_encode($products) ?>;
    const items = [];
    let selectedProduct = null;
    const suggestionsEl = document.getElementById('product-suggestions');
    const searchEl = document.getElementById('product-search');
    const listTable = document.getElementById('items-table');
    const totalEl = document.getElementById('total-display');
    const itemsInput = document.getElementById('items-input');
    const noClient = document.getElementById('no_client');
    const clientSelect = document.querySelector('select[name=\"client_id\"]');
    const newClientName = document.querySelector('input[name=\"new_client_name\"]');
    const newClientPhone = document.querySelector('input[name=\"new_client_phone\"]');

    if (noClient) {
        noClient.addEventListener('change', () => {
            const disabled = noClient.checked;
            clientSelect.disabled = disabled;
            newClientName.disabled = disabled;
            newClientPhone.disabled = disabled;
            clientSelect.classList.toggle('opacity-50', disabled);
            newClientName.classList.toggle('opacity-50', disabled);
            newClientPhone.classList.toggle('opacity-50', disabled);
        });
    }

    function formatCurrency(v) {
        return 'R$ ' + (v || 0).toFixed(2).replace('.', ',');
    }

    function renderItems() {
        listTable.innerHTML = '';
        let total = 0;
        if (items.length === 0) {
            const empty = document.createElement('tr');
            empty.innerHTML = '<td class="px-3 py-3 text-center text-slate-500 dark:text-slate-400" colspan="5">Nenhum item adicionado.</td>';
            listTable.appendChild(empty);
        } else {
            items.forEach((it, idx) => {
                const tr = document.createElement('tr');
                tr.className = 'border-t border-slate-100 dark:border-slate-800';
                const subtotal = it.qty * it.price;
                tr.innerHTML = `
                    <td class="px-3 py-2 font-medium">${it.name}</td>
                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300">${it.qty}</td>
                    <td class="px-3 py-2 text-slate-600 dark:text-slate-300">${formatCurrency(it.price)}</td>
                    <td class="px-3 py-2 text-slate-800 dark:text-slate-100">${formatCurrency(subtotal)}</td>
                    <td class="px-3 py-2 text-right"><button type="button" data-remove="${idx}" class="text-sm text-red-600 hover:underline">remover</button></td>
                `;
                listTable.appendChild(tr);
                total += subtotal;
            });
        }
        totalEl.textContent = formatCurrency(total);
        itemsInput.value = JSON.stringify(items);
    }

    function updateSuggestions(filter) {
        suggestionsEl.innerHTML = '';
        const term = (filter || '').trim().toLowerCase();
        if (term === '') { suggestionsEl.classList.add('hidden'); selectedProduct = null; return; }
        const matched = products.filter(p => p.nome.toLowerCase().includes(term)).slice(0, 8);
        if (matched.length === 0) { suggestionsEl.classList.add('hidden'); selectedProduct = null; return; }
        matched.forEach(p => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-full text-left px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-800 flex justify-between';
            btn.innerHTML = `<span>${p.nome}</span><span class="text-slate-500">${formatCurrency(parseFloat(p.preco))}</span>`;
            btn.addEventListener('click', () => {
                searchEl.value = p.nome;
                selectedProduct = p;
                suggestionsEl.classList.add('hidden');
                searchEl.focus();
            });
            suggestionsEl.appendChild(btn);
        });
        suggestionsEl.classList.remove('hidden');
    }

    function addSelected() {
        const qty = parseInt(document.getElementById('qty-input').value, 10) || 1;
        if (!selectedProduct) { return; }
        items.push({ id: parseInt(selectedProduct.id, 10), name: selectedProduct.nome, price: parseFloat(selectedProduct.preco), qty });
        renderItems();
    }

    searchEl.addEventListener('input', (e) => {
        updateSuggestions(e.target.value);
    });
    searchEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const first = suggestionsEl.querySelector('button');
            if (first) { first.click(); }
            addSelected();
        }
    });

    document.getElementById('add-item').addEventListener('click', () => {
        if (!selectedProduct) {
            const first = suggestionsEl.querySelector('button');
            if (first) { first.click(); }
        }
        addSelected();
    });

    document.getElementById('items-table').addEventListener('click', (e) => {
        if (e.target.dataset.remove !== undefined) {
            items.splice(parseInt(e.target.dataset.remove,10),1);
            renderItems();
        }
    });

    renderItems();
</script>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
