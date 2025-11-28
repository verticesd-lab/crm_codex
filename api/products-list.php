<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

checkApiToken();

$pdo = get_pdo();
$input = getMergedInput();

$companyId = (int)($input['company_id'] ?? 0);
$categoria = trim($input['categoria'] ?? '');
$busca = trim($input['busca'] ?? ($input['search'] ?? ''));

if (!$companyId) {
    apiJsonError('company_id obrigatorio');
}

try {
    $sql = 'SELECT id, nome, descricao, preco, categoria, tipo, destaque FROM products WHERE company_id = ? AND ativo = 1';
    $params = [$companyId];

    if ($categoria !== '') {
        $sql .= ' AND categoria = ?';
        $params[] = $categoria;
    }

    if ($busca !== '') {
        $sql .= ' AND nome LIKE ?';
        $params[] = '%' . $busca . '%';
    }

    $sql .= ' ORDER BY destaque DESC, nome ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    apiJsonResponse(true, ['products' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    apiJsonError('Erro ao listar produtos', 500);
}
