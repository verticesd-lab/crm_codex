<?php
/**
 * webhook_neo.php — Versão Híbrida V3 FINAL
 * ─────────────────────────────────────────────────────────────────
 * 1. Aceita XML do Google Drive (Vendas com Nota Fiscal)
 * 2. Aceita JSON do Script Python (Vendas direto do Banco de Dados)
 * 3. Identifica automaticamente a origem e processa o Cashback
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');

if (empty($rawInput)) {
    die(json_encode(["ok" => false, "reason" => "corpo_vazio"]));
}

$pdo = get_pdo();
$valorTotal = 0;
$telefone = '';
$nomeCliente = 'CONSUMIDOR';
$vendaIdIdentificador = ''; 
$companyId = 1; // ID padrão (Será atualizado pelo CNPJ se for XML)

// ============================================================
// 1. DETECÇÃO DE FORMATO E EXTRAÇÃO DE DADOS
// ============================================================

if (strpos($rawInput, '<?xml') !== false || strpos($rawInput, '<nfe') !== false || strpos($rawInput, '<enviNFe') !== false) {
    /* ════════════ MODO XML (GOOGLE DRIVE) ════════════ */
    $xmlString = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $rawInput);
    $xml = simplexml_load_string($xmlString);
    
    if (!$xml) die(json_encode(["ok" => false, "reason" => "xml_invalido"]));

    $infNFe = $xml->NFe->infNFe ?? $xml->nfeProc->NFe->infNFe ?? $xml->infNFe;
    if (!$infNFe) {
         // Tenta encontrar infNFe em estruturas enviNFe
         $infNFe = $xml->xpath('//infNFe')[0] ?? null;
    }

    $vendaIdIdentificador = ltrim((string)($infNFe->attributes()['Id'] ?? ''), 'NFe');
    $valorTotal = (float)($infNFe->total->ICMSTot->vNF ?? 0);
    $nomeCliente = (string)($infNFe->dest->xNome ?? 'CONSUMIDOR');
    
    // Busca Telefone nas Observações ou no cadastro da nota
    $obs = (string)($infNFe->infAdic->infCpl ?? '');
    $telefone = extrair_telefone_limpo($obs);
    if (!$telefone) {
        $telefone = extrair_telefone_limpo((string)($infNFe->dest->enderDest->fone ?? ''));
    }

    // Identifica a empresa pelo CNPJ do XML
    $cnpjLoja = preg_replace('/\D/', '', (string)($infNFe->emit->CNPJ ?? ''));
    if ($cnpjLoja) {
        $st_emp = $pdo->prepare("SELECT id FROM companies WHERE documento LIKE ? LIMIT 1");
        $st_emp->execute(['%' . $cnpjLoja . '%']);
        $emp = $st_emp->fetch();
        if ($emp) $companyId = $emp['id'];
    }

} else {
    /* ════════════ MODO JSON (SCRIPT PYTHON / BANCO) ════════════ */
    $json = json_decode($rawInput, true);
    if (!$json) die(json_encode(["ok" => false, "reason" => "json_invalido"]));

    $vendaIdIdentificador = "DB_" . ($json['venda_id'] ?? time());
    $valorTotal = (float)($json['valor'] ?? 0);
    $nomeCliente = $json['cliente'] ?? 'CONSUMIDOR';
    $telefone = extrair_telefone_limpo($json['telefone'] ?? $json['whatsapp'] ?? '');
    if(isset($json['company_id'])) $companyId = $json['company_id'];
}

// ============================================================
// 2. PROCESSAMENTO DO CASHBACK
// ============================================================

// A. Idempotência (Evita duplicar cashback para a mesma nota/venda)
$check = $pdo->prepare("SELECT id FROM club_transactions WHERE referencia_id = ? AND company_id = ?");
$check->execute([$vendaIdIdentificador, $companyId]);
if ($check->fetch()) {
    die(json_encode(["ok" => false, "reason" => "venda_ja_processada", "id" => $vendaIdIdentificador]));
}

// B. Busca Regras
$rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id = ? LIMIT 1");
$rules->execute([$companyId]);
$regra = $rules->fetch();

if (!$regra || !$regra['ativo']) {
    die(json_encode(["ok" => false, "reason" => "clube_inativo"]));
}

// C. Busca Cliente no CRM
$client = null;
$isNovoCliente = false;

if ($telefone) {
    $last8 = substr($telefone, -8);
    $st_cli = $pdo->prepare("SELECT id, nome FROM clients WHERE company_id = ? AND (whatsapp LIKE ? OR documento = ?)");
    $st_cli->execute([$companyId, '%' . $last8, $telefone]);
    $client = $st_cli->fetch();
}

// Auto-cadastra cliente identificado na venda quando ainda não existe no CRM.
if (!$client && $telefone && $nomeCliente !== 'CONSUMIDOR') {
    $pdo->prepare("INSERT INTO clients (company_id, nome, whatsapp, status, created_at) VALUES (?, ?, ?, 'ativo', NOW())")
        ->execute([$companyId, $nomeCliente, $telefone]);

    $newId = $pdo->lastInsertId();
    $client = [
        "id" => $newId,
        "nome" => $nomeCliente
    ];
    $isNovoCliente = true;
}

if (!$client) {
    die(json_encode([
        "ok" => false, 
        "reason" => "venda_sem_identificacao", 
        "venda_id" => $vendaIdIdentificador,
        "valor" => $valorTotal
    ]));
}

// D. Cálculo e Validação
if ($valorTotal < (float)$regra['cashback_minimo']) {
    die(json_encode(["ok" => false, "reason" => "valor_abaixo_do_minimo"]));
}

$cashback = round($valorTotal * ($regra['cashback_pct'] / 100), 2);
$validade = date('Y-m-d', strtotime("+" . $regra['cashback_validade'] . " days"));

// E. Atualiza Carteira (club_wallets)
$st_w = $pdo->prepare("SELECT id, saldo FROM club_wallets WHERE company_id = ? AND client_id = ?");
$st_w->execute([$companyId, $client['id']]);
$wallet = $st_w->fetch();

if (!$wallet) {
    $pdo->prepare("INSERT INTO club_wallets (company_id, client_id, saldo, total_ganho) VALUES (?, ?, ?, ?)")
        ->execute([$companyId, $client['id'], $cashback, $cashback]);
    $walletId = $pdo->lastInsertId();
    $saldoAtual = $cashback;
} else {
    $walletId = $wallet['id'];
    $pdo->prepare("UPDATE club_wallets SET saldo = saldo + ?, total_ganho = total_ganho + ?, updated_at = NOW() WHERE id = ?")
        ->execute([$cashback, $cashback, $walletId]);
    $saldoAtual = (float)$wallet['saldo'] + $cashback;
}

// F. Registra Transação (club_transactions)
$pdo->prepare("INSERT INTO club_transactions (company_id, client_id, wallet_id, tipo, valor, descricao, referencia_tipo, referencia_id, expira_em, created_at) 
               VALUES (?, ?, ?, 'credito', ?, ?, 'venda', ?, ?, NOW())")
    ->execute([$companyId, $client['id'], $walletId, $cashback, "Cashback automático NEO", $vendaIdIdentificador, $validade]);

// G. Resposta Final
echo json_encode([
    "ok" => true,
    "cliente" => $client['nome'],
    "primeiro_nome" => explode(' ', trim($client['nome']))[0],
    "telefone" => $telefone,
    "cashback_gerado" => number_format($cashback, 2, ',', '.'),
    "saldo_total" => number_format($saldoAtual, 2, ',', '.'),
    "valor_compra" => number_format($valorTotal, 2, ',', '.'),
    "cliente_criado" => $isNovoCliente
]);

// FUNÇÃO AUXILIAR
function extrair_telefone_limpo($texto) {
    $limpo = preg_replace('/\D/', '', (string)$texto);
    if (strlen($limpo) >= 10) return $limpo;
    return '';
}
