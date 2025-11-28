<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = get_pdo();

function api_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'data' => null, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Leitura de entrada (JSON ou form)
$raw = file_get_contents('php://input');
$json = json_decode((string)$raw, true);
$input = is_array($json) ? $json : $_POST;

$token = $input['token'] ?? '';
$companyId = (int)($input['company_id'] ?? 0);
$telefone = trim($input['telefone'] ?? '');
$mensagemCliente = trim($input['mensagem_cliente'] ?? '');
$mensagemIa = trim($input['mensagem_ia'] ?? '');
$canal = strtolower(trim($input['canal'] ?? 'whatsapp'));

// Token
if ($token !== API_TOKEN_IA) {
    api_error('Token invalido', 401);
}

// Validacoes basicas
if (!$companyId || $telefone === '' || $mensagemCliente === '' || ($canal !== 'whatsapp' && $canal !== 'instagram')) {
    api_error('Campos obrigatorios: company_id, telefone, mensagem_cliente, canal (whatsapp|instagram)');
}

try {
    $pdo->beginTransaction();

    // Buscar cliente pelo whatsapp
    $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE company_id = ? AND whatsapp = ? LIMIT 1');
    $clientStmt->execute([$companyId, $telefone]);
    $client = $clientStmt->fetch();

    if ($client) {
        $clientId = (int)$client['id'];
    } else {
        // Criar cliente novo
        $nome = 'Cliente WhatsApp ' . $telefone;
        $insertClient = $pdo->prepare('INSERT INTO clients (company_id, nome, telefone_principal, whatsapp, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $insertClient->execute([$companyId, $nome, $telefone, $telefone]);
        $clientId = (int)$pdo->lastInsertId();
    }

    // Montar resumo
    $resumoParts = [];
    $resumoParts[] = "Mensagem do cliente:\n" . $mensagemCliente;
    if ($mensagemIa !== '') {
        $resumoParts[] = "Resposta da IA:\n" . $mensagemIa;
    }
    $resumo = implode("\n\n", $resumoParts);

    // Criar interacao
    $interaction = $pdo->prepare('INSERT INTO interactions (company_id, client_id, canal, origem, titulo, resumo, atendente, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $interaction->execute([
        $companyId,
        $clientId,
        $canal,
        'ia',
        'Atendimento via ' . strtoupper($canal),
        $resumo,
        'IA',
    ]);

    // Atualizar ultimo atendimento
    $updateClient = $pdo->prepare('UPDATE clients SET ultimo_atendimento_em = NOW(), updated_at = NOW() WHERE id = ?');
    $updateClient->execute([$clientId]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'data' => [
            'client_id' => $clientId,
            'company_id' => $companyId,
        ],
        'error' => null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error('Erro ao registrar atendimento', 500);
}
