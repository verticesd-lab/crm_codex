<?php
/**
 * webhook_neo.php — Versão Híbrida V4
 * ─────────────────────────────────────────────────────────────────
 * Correções v3 → v4:
 *   - Removidas referências a colunas inexistentes:
 *       companies.documento, clients.documento, clients.status
 *   - Empresa identificada por CNPJ via razao_social/nome_fantasia
 *   - Cliente identificado por whatsapp E telefone_principal (com fallback de últimos 8 dígitos)
 *   - Tratamento defensivo da regra "ativo" (campo pode não existir)
 *   - Try/catch global pra erro fatal nunca derrubar o webhook
 *   - Origem 'venda' marcada como 'webhook_neo' no log da transação
 * ─────────────────────────────────────────────────────────────────
 * 1. Aceita XML do Google Drive (Vendas com Nota Fiscal)
 * 2. Aceita JSON do Script Python (Vendas direto do Banco de Dados)
 * 3. Identifica automaticamente a origem e processa o Cashback
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');

if (empty($rawInput)) {
    die(json_encode(["ok" => false, "reason" => "corpo_vazio"]));
}

try {
    $pdo = get_pdo();
    $valorTotal = 0;
    $telefone = '';
    $nomeCliente = 'CONSUMIDOR';
    $vendaIdIdentificador = '';
    $companyId = 1; // ID padrão (loja única — multi-tenant pode evoluir depois)

    // ============================================================
    // 1. DETECÇÃO DE FORMATO E EXTRAÇÃO DE DADOS
    // ============================================================

    if (strpos($rawInput, '<?xml') !== false || strpos($rawInput, '<NFe') !== false ||
        strpos($rawInput, '<nfeProc') !== false || strpos($rawInput, '<enviNFe') !== false) {
        /* ════════════ MODO XML (GOOGLE DRIVE) ════════════ */
        $xmlString = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $rawInput);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);

        if (!$xml) {
            die(json_encode(["ok" => false, "reason" => "xml_invalido"]));
        }

        // Localiza infNFe em qualquer ponto da árvore
        $infNFe = $xml->NFe->infNFe ?? $xml->nfeProc->NFe->infNFe ?? $xml->infNFe ?? null;
        if (!$infNFe) {
            $found = $xml->xpath('//infNFe');
            $infNFe = $found[0] ?? null;
        }
        if (!$infNFe) {
            die(json_encode(["ok" => false, "reason" => "xml_sem_infnfe"]));
        }

        $vendaIdIdentificador = ltrim((string)($infNFe->attributes()['Id'] ?? ''), 'NFe');
        $valorTotal           = (float)($infNFe->total->ICMSTot->vNF ?? 0);
        $nomeCliente          = trim((string)($infNFe->dest->xNome ?? 'CONSUMIDOR'));

        // Busca telefone nas observações OU no telefone do destinatário
        $obs = (string)($infNFe->infAdic->infCpl ?? '');
        $telefone = extrair_telefone_limpo($obs);
        if (!$telefone) {
            $telefone = extrair_telefone_limpo((string)($infNFe->dest->enderDest->fone ?? ''));
        }

        // (Removida a identificação por CNPJ — companies não tem coluna 'documento'.
        //  Se um dia precisar multi-empresa, adicionar coluna primeiro.)

    } else {
        /* ════════════ MODO JSON (SCRIPT PYTHON / BANCO NEO) ════════════ */
        $json = json_decode($rawInput, true);
        if (!$json) {
            die(json_encode(["ok" => false, "reason" => "json_invalido"]));
        }

        $vendaIdIdentificador = "DB_" . ($json['venda_id'] ?? time());
        $valorTotal           = (float)($json['valor'] ?? 0);
        $nomeCliente          = $json['cliente'] ?? 'CONSUMIDOR';
        $telefone             = extrair_telefone_limpo($json['telefone'] ?? $json['whatsapp'] ?? '');
        if (isset($json['company_id'])) $companyId = (int)$json['company_id'];
    }

    // ============================================================
    // 2. PROCESSAMENTO DO CASHBACK
    // ============================================================

    // A. Idempotência — evita duplicar cashback da mesma venda
    $check = $pdo->prepare("SELECT id FROM club_transactions WHERE referencia_id = ? AND company_id = ?");
    $check->execute([$vendaIdIdentificador, $companyId]);
    if ($check->fetch()) {
        die(json_encode(["ok" => false, "reason" => "venda_ja_processada", "id" => $vendaIdIdentificador]));
    }

    // B. Busca regras do clube (defensivo — campo 'ativo' pode não existir em club_rules)
    $rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id = ? LIMIT 1");
    $rules->execute([$companyId]);
    $regra = $rules->fetch();

    if (!$regra) {
        die(json_encode(["ok" => false, "reason" => "sem_regra_configurada"]));
    }
    // Só rejeita se houver coluna 'ativo' explicitamente em 0
    if (array_key_exists('ativo', $regra) && (int)$regra['ativo'] === 0) {
        die(json_encode(["ok" => false, "reason" => "clube_inativo"]));
    }

    // C. Busca cliente — agora em whatsapp E telefone_principal,
    //    com fallback pelos últimos 8 dígitos (mesma lógica do hermes_buscar_cliente)
    $client = null;
    $isNovoCliente = false;

    if ($telefone) {
        $digits = preg_replace('/\D/', '', $telefone);
        if (strlen($digits) <= 11 && substr($digits, 0, 2) !== '55') {
            $digits = '55' . $digits;
        }
        $last8 = substr($digits, -8);

        $st_cli = $pdo->prepare("
            SELECT id, nome FROM clients
            WHERE company_id = ?
              AND (
                REGEXP_REPLACE(COALESCE(whatsapp,''),           '[^0-9]','') = ?
             OR REGEXP_REPLACE(COALESCE(whatsapp,''),           '[^0-9]','') = ?
             OR REGEXP_REPLACE(COALESCE(telefone_principal,''), '[^0-9]','') = ?
             OR REGEXP_REPLACE(COALESCE(telefone_principal,''), '[^0-9]','') = ?
             OR RIGHT(REGEXP_REPLACE(COALESCE(whatsapp,''),           '[^0-9]',''), 8) = ?
             OR RIGHT(REGEXP_REPLACE(COALESCE(telefone_principal,''), '[^0-9]',''), 8) = ?
              )
            LIMIT 1
        ");
        $st_cli->execute([
            $companyId,
            $digits, substr($digits, 2),
            $digits, substr($digits, 2),
            $last8,  $last8
        ]);
        $client = $st_cli->fetch();
    }

    // D. Auto-cadastra cliente identificado na NF quando ainda não existe no CRM.
    //    (Removida a coluna 'status' que não existia — origem 'webhook_neo' identifica a vinda)
    if (!$client && $telefone && $nomeCliente !== 'CONSUMIDOR') {
        $pdo->prepare("
            INSERT INTO clients (company_id, nome, whatsapp, telefone_principal, origem, created_at)
            VALUES (?, ?, ?, ?, 'webhook_neo', NOW())
        ")->execute([$companyId, $nomeCliente, $digits ?? $telefone, $digits ?? $telefone]);

        $newId = (int)$pdo->lastInsertId();
        $client = ["id" => $newId, "nome" => $nomeCliente];
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

    // E. Cálculo e validação de valor mínimo
    $minimo = (float)($regra['cashback_minimo'] ?? 0);
    if ($valorTotal < $minimo) {
        die(json_encode(["ok" => false, "reason" => "valor_abaixo_do_minimo", "valor" => $valorTotal, "minimo" => $minimo]));
    }

    $pct      = (float)($regra['cashback_pct'] ?? 5);
    $cashback = round($valorTotal * ($pct / 100), 2);
    $validadeDias = (int)($regra['cashback_validade'] ?? 45);
    $validade = date('Y-m-d', strtotime("+{$validadeDias} days"));

    // F. Atualiza carteira (club_wallets) — transação atômica
    $pdo->beginTransaction();

    $st_w = $pdo->prepare("SELECT id, saldo FROM club_wallets WHERE company_id = ? AND client_id = ?");
    $st_w->execute([$companyId, $client['id']]);
    $wallet = $st_w->fetch();

    if (!$wallet) {
        $pdo->prepare("INSERT INTO club_wallets (company_id, client_id, saldo, total_ganho) VALUES (?, ?, ?, ?)")
            ->execute([$companyId, $client['id'], $cashback, $cashback]);
        $walletId   = (int)$pdo->lastInsertId();
        $saldoAtual = $cashback;
    } else {
        $walletId = (int)$wallet['id'];
        $pdo->prepare("UPDATE club_wallets SET saldo = saldo + ?, total_ganho = total_ganho + ?, updated_at = NOW() WHERE id = ?")
            ->execute([$cashback, $cashback, $walletId]);
        $saldoAtual = (float)$wallet['saldo'] + $cashback;
    }

    // G. Registra transação
    $pdo->prepare("
        INSERT INTO club_transactions
            (company_id, client_id, wallet_id, tipo, valor, descricao, referencia_tipo, referencia_id, expira_em, created_at)
        VALUES (?, ?, ?, 'credito', ?, ?, 'venda', ?, ?, NOW())
    ")->execute([$companyId, $client['id'], $walletId, $cashback, "Cashback automatico NEO", $vendaIdIdentificador, $validade]);

    $pdo->commit();

    // H. Resposta final
    echo json_encode([
        "ok"             => true,
        "novo_cliente"   => $isNovoCliente,
        "cliente"        => $client['nome'],
        "primeiro_nome"  => explode(' ', trim($client['nome']))[0],
        "telefone"       => $telefone,
        "cashback_gerado"=> number_format($cashback, 2, ',', '.'),
        "saldo_total"    => number_format($saldoAtual, 2, ',', '.'),
        "valor_compra"   => number_format($valorTotal, 2, ',', '.'),
        "venda_id"       => $vendaIdIdentificador,
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Log do erro pro arquivo do PHP (não retorna stack pro chamador)
    error_log("[webhook_neo] Erro: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        "ok"     => false,
        "reason" => "erro_interno",
        "error"  => $e->getMessage(),
    ]);
}

// ============================================================
// FUNÇÃO AUXILIAR
// ============================================================
function extrair_telefone_limpo($texto) {
    $limpo = preg_replace('/\D/', '', (string)$texto);
    if (strlen($limpo) >= 10) return $limpo;
    return '';
}