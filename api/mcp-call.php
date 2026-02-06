<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/utils.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiJsonError('Method not allowed', 405);
}

$pdo = get_pdo();
$payload = getMergedInput();
$token = trim((string)($payload['token'] ?? ''));

if ($token === '') {
    apiJsonError('token obrigatorio', 401);
}
if (!defined('API_SECRET') || trim((string)API_SECRET) === '') {
    apiJsonError('API_SECRET nao configurado', 500);
}
if (!hash_equals((string)API_SECRET, $token)) {
    apiJsonError('token invalido', 401);
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$sessionCompanyId = resolve_company_id_by_token($token, $pdo);
if ($sessionCompanyId <= 0) {
    apiJsonError('token sem empresa vinculada', 403);
}
$_SESSION['company_id'] = $sessionCompanyId;
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 0;
}

$tool = trim((string)($payload['tool'] ?? ''));
$tool = strtolower(str_replace(['-', ' '], '_', $tool));

$args = [];
if (isset($payload['input']) && is_array($payload['input'])) {
    $args = $payload['input'];
} elseif (isset($payload['args']) && is_array($payload['args'])) {
    $args = $payload['args'];
} else {
    $args = $payload;
    unset($args['tool'], $args['token'], $args['input'], $args['args']);
}

if (isset($payload['company_id']) && !isset($args['company_id'])) {
    $args['company_id'] = $payload['company_id'];
}

if (isset($args['company_id']) && (int)$args['company_id'] !== $sessionCompanyId) {
    apiJsonError('company_id invalido para este token', 403);
}
$args['company_id'] = $sessionCompanyId;

if ($tool === '') {
    apiJsonError('tool obrigatorio');
}

switch ($tool) {
    case 'client_search':
        handle_client_search($pdo, $args);
        break;
    case 'client_create_or_update':
        handle_client_create_or_update($pdo, $args);
        break;
    case 'interaction_create':
        handle_interaction_create($pdo, $args);
        break;
    case 'order_create_from_chat':
        handle_order_create_from_chat($pdo, $args);
        break;
    case 'products_list':
        handle_products_list($pdo, $args);
        break;
    case 'client_timeline':
        handle_client_timeline($pdo, $args);
        break;
    case 'lock_ai':
        handle_lock_ai($pdo, $args);
        break;
    default:
        apiJsonError('tool invalido');
}

function resolve_company_id_by_token(string $token, PDO $pdo): int
{
    // TODO: mapear token -> empresa no banco.
    return 1;
}

function handle_client_search(PDO $pdo, array $input): void
{
    $companyId = (int)($input['company_id'] ?? 0);
    $phone = trim((string)($input['phone'] ?? ($input['telefone'] ?? ($input['whatsapp'] ?? ''))));
    $instagram = trim((string)($input['instagram'] ?? ($input['instagram_username'] ?? '')));

    if (!$companyId) {
        apiJsonError('company_id obrigatorio');
    }
    if ($phone === '' && $instagram === '') {
        apiJsonError('Informe phone ou instagram');
    }

    try {
        $client = null;

        if ($phone !== '') {
            $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND (telefone_principal = ? OR whatsapp = ?) LIMIT 1');
            $stmt->execute([$companyId, $phone, $phone]);
            $client = $stmt->fetch();
        }

        if (!$client && $instagram !== '') {
            $handle = ltrim($instagram, '@');
            $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND instagram_username = ? LIMIT 1');
            $stmt->execute([$companyId, $handle]);
            $client = $stmt->fetch();
        }

        if (!$client) {
            apiJsonResponse(false, null, 'Cliente nao encontrado');
        }

        $ordersStmt = $pdo->prepare('SELECT id, origem, status, total, created_at FROM orders WHERE company_id = ? AND client_id = ? ORDER BY created_at DESC LIMIT 5');
        $ordersStmt->execute([$companyId, $client['id']]);

        $interactionsStmt = $pdo->prepare('SELECT id, canal, origem, titulo, resumo, atendente, created_at FROM interactions WHERE company_id = ? AND client_id = ? ORDER BY created_at DESC LIMIT 5');
        $interactionsStmt->execute([$companyId, $client['id']]);

        apiJsonResponse(true, [
            'client' => $client,
            'orders' => $ordersStmt->fetchAll(),
            'interactions' => $interactionsStmt->fetchAll(),
        ]);
    } catch (Throwable $e) {
        apiJsonError('Erro ao buscar cliente', 500);
    }
}

function handle_client_create_or_update(PDO $pdo, array $input): void
{
    $companyId = (int)($input['company_id'] ?? 0);
    $nome = trim((string)($input['nome'] ?? ''));
    $telefone = trim((string)($input['telefone'] ?? ($input['whatsapp'] ?? '')));
    $instagram = trim((string)($input['instagram_username'] ?? ($input['instagram'] ?? '')));
    $email = trim((string)($input['email'] ?? ''));
    $tags = trim((string)($input['tags'] ?? ''));

    if (!$companyId || $nome === '') {
        apiJsonError('Informe company_id e nome');
    }

    $instagramHandle = $instagram !== '' ? ltrim($instagram, '@') : '';

    try {
        $client = null;

        if ($telefone !== '') {
            $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND (telefone_principal = ? OR whatsapp = ?) LIMIT 1');
            $stmt->execute([$companyId, $telefone, $telefone]);
            $client = $stmt->fetch();
        }

        if (!$client && $instagramHandle !== '') {
            $stmt = $pdo->prepare('SELECT * FROM clients WHERE company_id = ? AND instagram_username = ? LIMIT 1');
            $stmt->execute([$companyId, $instagramHandle]);
            $client = $stmt->fetch();
        }

        if ($client) {
            $newTelefone = $telefone !== '' ? $telefone : ($client['telefone_principal'] ?? '');
            $newInstagram = $instagramHandle !== '' ? $instagramHandle : ($client['instagram_username'] ?? '');
            $newEmail = $email !== '' ? $email : ($client['email'] ?? '');
            $newTags = $tags !== '' ? $tags : ($client['tags'] ?? '');

            $update = $pdo->prepare('UPDATE clients SET nome = ?, telefone_principal = ?, whatsapp = ?, instagram_username = ?, email = ?, tags = ?, updated_at = NOW() WHERE id = ? AND company_id = ?');
            $update->execute([
                $nome,
                $newTelefone,
                $newTelefone,
                $newInstagram,
                $newEmail,
                $newTags,
                $client['id'],
                $companyId,
            ]);

            $clientId = (int)$client['id'];
            $action = 'updated';
        } else {
            $insert = $pdo->prepare('INSERT INTO clients (company_id, nome, telefone_principal, whatsapp, instagram_username, email, tags, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $insert->execute([
                $companyId,
                $nome,
                $telefone,
                $telefone,
                $instagramHandle,
                $email,
                $tags,
            ]);
            $clientId = (int)$pdo->lastInsertId();
            $action = 'created';
        }

        $fetch = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND company_id = ?');
        $fetch->execute([$clientId, $companyId]);
        $clientData = $fetch->fetch();

        apiJsonResponse(true, [
            'client_id' => $clientId,
            'action' => $action,
            'client' => $clientData,
        ]);
    } catch (Throwable $e) {
        apiJsonError('Erro ao criar ou atualizar cliente', 500);
    }
}

function handle_interaction_create(PDO $pdo, array $input): void
{
    $companyId = (int)($input['company_id'] ?? 0);
    $clientId = (int)($input['client_id'] ?? 0);
    $canal = trim((string)($input['canal'] ?? 'whatsapp'));
    $origem = trim((string)($input['origem'] ?? 'ia'));
    $titulo = trim((string)($input['titulo'] ?? ''));
    $resumo = trim((string)($input['resumo'] ?? ''));
    $atendente = trim((string)($input['atendente'] ?? 'IA'));

    if (!$companyId || !$clientId || $titulo === '' || $resumo === '') {
        apiJsonError('Campos obrigatorios ausentes: company_id, client_id, titulo, resumo');
    }

    try {
        $clientCheck = $pdo->prepare('SELECT id FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
        $clientCheck->execute([$clientId, $companyId]);
        if (!$clientCheck->fetch()) {
            apiJsonError('Cliente nao pertence a esta empresa', 404);
        }

        $stmt = $pdo->prepare('INSERT INTO interactions (company_id, client_id, canal, origem, titulo, resumo, atendente, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$companyId, $clientId, $canal, $origem, $titulo, $resumo, $atendente]);
        $interactionId = (int)$pdo->lastInsertId();

        $pdo->prepare('UPDATE clients SET ultimo_atendimento_em = NOW(), updated_at = NOW() WHERE id = ?')->execute([$clientId]);

        $fetch = $pdo->prepare('SELECT id, company_id, client_id, canal, origem, titulo, resumo, atendente, created_at FROM interactions WHERE id = ?');
        $fetch->execute([$interactionId]);

        apiJsonResponse(true, [
            'interaction' => $fetch->fetch(),
        ]);
    } catch (Throwable $e) {
        apiJsonError('Erro ao registrar interacao', 500);
    }
}

function handle_order_create_from_chat(PDO $pdo, array $input): void
{
    $companyId = (int)($input['company_id'] ?? 0);
    $clientId = (int)($input['client_id'] ?? 0);
    $origem = trim((string)($input['origem'] ?? 'ia'));
    $canal = trim((string)($input['canal'] ?? ''));
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
}

function handle_products_list(PDO $pdo, array $input): void
{
    $companyId = (int)($input['company_id'] ?? 0);
    $categoria = trim((string)($input['categoria'] ?? ''));
    $busca = trim((string)($input['busca'] ?? ($input['search'] ?? '')));

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
}

function build_resumo_display($resumo): array
{
    $raw = is_string($resumo) ? trim($resumo) : '';

    if ($raw === '') {
        return ['parsed' => null, 'display' => ''];
    }

    $parsed = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
        $intent = $parsed['intent'] ?? null;
        $confidence = $parsed['confidence'] ?? null;

        if (is_string($intent) && $intent !== '') {
            if (is_numeric($confidence)) {
                $pct = (int) round(((float)$confidence) * 100);
                return ['parsed' => $parsed, 'display' => $intent . " ({$pct}%)"];
            }
            return ['parsed' => $parsed, 'display' => $intent];
        }

        $compact = json_encode($parsed, JSON_UNESCAPED_UNICODE);
        if (is_string($compact)) {
            if (mb_strlen($compact) > 220) {
                $compact = mb_substr($compact, 0, 220) . '...';
            }
            return ['parsed' => $parsed, 'display' => $compact];
        }

        return ['parsed' => $parsed, 'display' => $raw];
    }

    if (mb_strlen($raw) > 220) {
        $raw = mb_substr($raw, 0, 220) . '...';
    }

    return ['parsed' => null, 'display' => $raw];
}

function handle_client_timeline(PDO $pdo, array $input): void
{
    $companyId = (int)($input['company_id'] ?? 0);
    $clientId = (int)($input['client_id'] ?? 0);

    if (!$companyId || !$clientId) {
        apiJsonError('Informe company_id e client_id');
    }

    try {
        $clientStmt = $pdo->prepare('SELECT * FROM clients WHERE id = ? AND company_id = ? LIMIT 1');
        $clientStmt->execute([$clientId, $companyId]);
        $client = $clientStmt->fetch();

        if (!$client) {
            apiJsonError('Cliente nao encontrado nesta empresa', 404);
        }

        $interactionsStmt = $pdo->prepare('
            SELECT id, canal, origem, titulo, resumo, atendente, created_at
            FROM interactions
            WHERE company_id = ? AND client_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ');
        $interactionsStmt->execute([$companyId, $clientId]);
        $interactions = $interactionsStmt->fetchAll();

        foreach ($interactions as &$it) {
            $info = build_resumo_display($it['resumo'] ?? '');
            $it['resumo_parsed'] = $info['parsed'];
            $it['resumo_display'] = $info['display'];
        }
        unset($it);

        $ordersStmt = $pdo->prepare('
            SELECT id, origem, status, total, created_at
            FROM orders
            WHERE company_id = ? AND client_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ');
        $ordersStmt->execute([$companyId, $clientId]);

        apiJsonResponse(true, [
            'client' => $client,
            'interactions' => $interactions,
            'orders' => $ordersStmt->fetchAll(),
        ]);
    } catch (Throwable $e) {
        apiJsonError('Erro ao montar timeline do cliente', 500);
    }
}

function handle_lock_ai(PDO $pdo, array $input): void
{
    $companyId = (int)($_SESSION['company_id'] ?? 0);
    $phone = preg_replace('/\D+/', '', (string)($input['phone'] ?? ''));
    $minutes = (int)($input['minutes'] ?? 0);

    if ($companyId <= 0) {
        apiJsonError('company_id ausente na sessao', 403);
    }
    if ($phone === '') {
        apiJsonError('phone obrigatorio');
    }
    if ($minutes <= 0) {
        apiJsonError('minutes obrigatorio e deve ser maior que zero');
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE atd_conversations
            SET ai_next_allowed_at = DATE_ADD(NOW(), INTERVAL :minutes MINUTE),
                updated_at = NOW()
            WHERE contact_phone = :phone
              AND company_id = :company_id
        ");
        $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
        $stmt->bindValue(':phone', $phone, PDO::PARAM_STR);
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->execute();

        apiJsonResponse(true, [
            'locked' => true,
            'phone' => $phone,
            'minutes' => $minutes,
            'company_id' => $companyId,
            'affected_rows' => $stmt->rowCount(),
        ]);
    } catch (Throwable $e) {
        apiJsonError('Erro ao aplicar lock_ai', 500);
    }
}
