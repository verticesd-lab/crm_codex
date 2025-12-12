<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();
$pdo = get_pdo();

$companyId = current_company_id();

// produto alvo
$productId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ?');
$stmt->execute([$productId, $companyId]);
$product = $stmt->fetch();

if (!$product) {
    echo 'Produto não encontrado.';
    exit;
}

// Se enviou formulário de ajuste
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['variant'] ?? [] as $variantId => $novaQtd) {
        $variantId = (int)$variantId;
        $novaQtd   = (int)$novaQtd;

        // quantidade atual
        $stmt = $pdo->prepare("
            SELECT COALESCE(quantity, 0) AS quantity
            FROM stock_balances
            WHERE product_variant_id = ? AND location = 'loja_fisica'
        ");
        $stmt->execute([$variantId]);
        $row   = $stmt->fetch();
        $atual = $row ? (int)$row['quantity'] : 0;

        if ($atual === $novaQtd) {
            continue;
        }

        $dif = $novaQtd - $atual;

        // tenta pegar o usuário logado da sessão (se existir)
        $userId = $_SESSION['user_id'] ?? null;

        // registra movimento de ajuste
        $stmtMov = $pdo->prepare("
            INSERT INTO stock_movements
                (product_variant_id, movement_type, quantity, reason, user_id, created_at)
            VALUES
                (?, 'ajuste', ?, 'ajuste manual no painel', ?, NOW())
        ");
        $stmtMov->execute([
            $variantId,
            abs($dif),
            $userId
        ]);

        // atualiza saldo
        if ($row) {
            $stmtBal = $pdo->prepare("
                UPDATE stock_balances
                   SET quantity = ?, updated_at = NOW()
                 WHERE product_variant_id = ? AND location = 'loja_fisica'
            ");
            $stmtBal->execute([$novaQtd, $variantId]);
        } else {
            $stmtBal = $pdo->prepare("
                INSERT INTO stock_balances
                    (product_variant_id, location, quantity, updated_at)
                VALUES
                    (?, 'loja_fisica', ?, NOW())
            ");
            $stmtBal->execute([$variantId, $novaQtd]);
        }
    }

    flash('success', 'Estoque atualizado com sucesso.');
    redirect('product_stock.php?id=' . $productId);
}

// Carrega variantes + saldo
$stmt = $pdo->prepare("
    SELECT
        pv.id,
        pv.size,
        COALESCE(b.quantity, 0) AS quantity
    FROM product_variants pv
    LEFT JOIN stock_balances b
        ON b.product_variant_id = pv.id
       AND b.location = 'loja_fisica'
    WHERE pv.product_id = ?
    ORDER BY pv.size
");
$stmt->execute([$productId]);
$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flashSuccess = get_flash('success');

include __DIR__ . '/views/partials/header.php';
?>
<div class="space-y-4">
    <h1 class="text-2xl font-semibold">Estoque – <?= sanitize($product['nome']) ?></h1>

    <?php if ($flashSuccess): ?>
        <div class="p-3 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
            <?= sanitize($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl p-4 space-y-4">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700">
                    <th class="text-left py-2">Tamanho</th>
                    <th class="text-left py-2">Quantidade em estoque</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($variants as $v): ?>
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <td class="py-2"><?= sanitize($v['size']) ?></td>
                        <td class="py-2">
                            <input
                                type="number"
                                name="variant[<?= (int)$v['id'] ?>]"
                                value="<?= (int)$v['quantity'] ?>"
                                min="0"
                                class="w-24 rounded border-slate-300 dark:bg-slate-900 dark:border-slate-700 text-sm"
                            >
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="flex justify-between mt-4">
            <a href="products.php" class="text-sm text-slate-600 dark:text-slate-300 hover:underline">
                Voltar
            </a>
            <button type="submit"
                    class="px-6 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                Salvar estoque
            </button>
        </div>
    </form>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
