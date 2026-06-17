<?php
/**
 * webhook_neo.php — v2
 * ─────────────────────────────────────────────────────────────────
 * Aceita 2 modos de operação:
 *
 * MODO A (recomendado): Activepieces envia só o fileId
 *   POST body: {"fileId": "1abc...", "accessToken": "ya29..."}
 *   O PHP busca o XML diretamente no Google Drive
 *
 * MODO B (fallback): Activepieces envia o XML bruto
 *   POST body: conteúdo XML direto (Content-Type: text/xml)
 *   Funciona como antes
 *
 * Resposta de sucesso:
 *   { "ok": true, "cliente": "João", "primeiro_nome": "João",
 *     "telefone": "556599999999", "cashback_gerado": "5.90",
 *     "saldo_total": "23.40", "valor_compra": "118.00" }
 * ─────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed']);
    exit;
}

/* ── Lê o corpo ── */
$rawBody = file_get_contents('php://input');
if (!$rawBody || strlen($rawBody) < 100) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'reason' => 'empty_body']);
    exit;
}

/* ── Detecta MODO A (JSON com fileId) ou MODO B (XML bruto) ── */
$jsonBody = json_decode($rawBody, true);
$xmlContent = null;

if ($jsonBody && isset($jsonBody['fileId'])) {
    /* ════ MODO A: busca o XML no Google Drive ════ */
    $fileId      = trim($jsonBody['fileId']);
    $accessToken = trim($jsonBody['accessToken'] ?? '');
    $refreshToken= trim($jsonBody['refreshToken'] ?? '');
    $clientId    = trim($jsonBody['clientId']    ?? (defined('GOOGLE_CLIENT_ID')     ? GOOGLE_CLIENT_ID     : ''));
    $clientSecret= trim($jsonBody['clientSecret']?? (defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : ''));

    /* Se não tem accessToken mas tem refreshToken, renova automaticamente */
    if (!$accessToken && $refreshToken && $clientId && $clientSecret) {
        $renewResp = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query([
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                ]),
                'timeout' => 10,
            ]
        ]));
        if ($renewResp) {
            $renewData   = json_decode($renewResp, true);
            $accessToken = $renewData['access_token'] ?? '';
        }
    }

    if (!$accessToken) {
        echo json_encode(['ok' => false, 'reason' => 'no_access_token', 'fileId' => $fileId]);
        exit;
    }

    /* Baixa o XML do Drive */
    $driveUrl = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media";
    $ch = curl_init($driveUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $driveResp = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$driveResp) {
        echo json_encode([
            'ok'     => false,
            'reason' => 'drive_download_failed',
            'code'   => $httpCode,
            'detail' => substr($driveResp ?: '', 0, 200),
        ]);
        exit;
    }
    $xmlContent = $driveResp;

} else {
    /* ════ MODO B: XML chegou direto no body ════ */
    $xmlContent = $rawBody;

    /* Tenta desembrulhar se veio como JSON com campo content/body */
    if ($jsonBody) {
        $xmlContent = $jsonBody['xml']
                   ?? $jsonBody['content']
                   ?? $jsonBody['body']
                   ?? $jsonBody['text']
                   ?? $jsonBody['data']
                   ?? $rawBody;
    }

    /* Tenta decodificar base64 (às vezes o Activepieces encoda) */
    if ($xmlContent && !str_contains($xmlContent, '<') && preg_match('/^[A-Za-z0-9+\/=\s]+$/', trim($xmlContent))) {
        $decoded = base64_decode($xmlContent, true);
        if ($decoded && str_contains($decoded, '<')) {
            $xmlContent = $decoded;
        }
    }
}

if (!$xmlContent || strlen($xmlContent) < 50) {
    echo json_encode(['ok' => false, 'reason' => 'xml_content_empty']);
    exit;
}

/* ════════════════════════════════════════════════════
   PARSE DO XML NF-e
════════════════════════════════════════════════════ */
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NOCDATA);

if (!$xml) {
    echo json_encode(['ok' => false, 'reason' => 'xml_parse_error', 'preview' => substr($xmlContent, 0, 100)]);
    exit;
}

/* Resolve namespace NF-e */
$ns      = $xml->getNamespaces(true);
$nfeNs   = $ns[''] ?? $ns['nfe'] ?? null;
$infNFe  = null;

if ($nfeNs) {
    $xml->registerXPathNamespace('n', $nfeNs);
    $nodes  = $xml->xpath('//n:infNFe');
    if ($nodes) $infNFe = $nodes[0];
} else {
    $infNFe = $xml->NFe->infNFe
           ?? $xml->nfeProc->NFe->infNFe
           ?? $xml->infNFe
           ?? null;
}

if (!$infNFe) {
    echo json_encode(['ok' => false, 'reason' => 'infnfe_not_found']);
    exit;
}

/* Extrai dados */
$chaveNFe   = ltrim((string)($infNFe->attributes()['Id'] ?? ''), 'NFe');
$valorTotal = (float)($infNFe->total->ICMSTot->vNF ?? $infNFe->total->ICMSTot->vProd ?? 0);
$dhEmi      = (string)($infNFe->ide->dhEmi ?? $infNFe->ide->dEmi ?? '');
$dataEmissao= $dhEmi ? date('Y-m-d H:i:s', strtotime($dhEmi)) : gmdate('Y-m-d H:i:s');

/* Telefone no campo observações */
$infAdic  = $infNFe->infAdic ?? null;
$campoObs = $infAdic ? (string)($infAdic->infCpl ?? $infAdic->xObs ?? '') : '';
$telefone = extrair_telefone($campoObs);

/* ════ Banco de dados ════ */
$pdo = get_pdo();

/* Descobre company_id pelo CNPJ */
$cnpjEmit  = preg_replace('/\D/', '', (string)($infNFe->emit->CNPJ ?? ''));
$companyId = null;
if ($cnpjEmit) {
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('cnpj', $cols)) {
            $st = $pdo->prepare("SELECT id FROM companies WHERE REPLACE(REPLACE(cnpj,'.',''),'/','') LIKE ? LIMIT 1");
            $st->execute(['%' . $cnpjEmit . '%']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) $companyId = (int)$row['id'];
        }
    } catch (Throwable $e) {}
}
if (!$companyId) {
    $st = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1");
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $companyId = $row ? (int)$row['id'] : 1;
}

/* Busca cliente */
$client = null;
if ($telefone) {
    $client = buscar_cliente_por_telefone($pdo, $companyId, $telefone);
}

/* Busca as regras originais do Clube For Men */
$rules = $pdo->prepare("SELECT * FROM club_rules WHERE company_id=? LIMIT 1");
$rules->execute([$companyId]);
$rules = $rules->fetch(PDO::FETCH_ASSOC);

if (!$rules || empty($rules['ativo'])) {
    echo json_encode(['ok' => false, 'reason' => 'clube_inativo_ou_sem_regras']);
    exit;
}

$pct          = (float)($rules['cashback_pct'] ?? 5.00);
$minimo       = (float)($rules['cashback_minimo'] ?? 0.00);
$validadeDias = (int)($rules['cashback_validade'] ?? 90);

$cashback = round($valorTotal * ($pct / 100), 2);
$expiraEm = date('Y-m-d', strtotime("+{$validadeDias} days"));

if ($valorTotal < $minimo) {
    echo json_encode([
        'ok'           => false,
        'reason'       => 'valor_abaixo_do_minimo',
        'valor_compra' => number_format($valorTotal, 2, '.', ''),
        'minimo'       => number_format($minimo, 2, '.', ''),
    ]);
    exit;
}

$clientId   = $client ? (int)$client['id'] : null;
$clientNome = $client ? $client['nome']    : 'Cliente';

if (!$telefone || !$client) {
    echo json_encode([
        'ok'              => false,
        'reason'          => $telefone ? 'cliente_nao_cadastrado' : 'telefone_nao_encontrado',
        'valor_compra'    => number_format($valorTotal, 2, '.', ''),
        'cashback_gerado' => number_format($cashback,   2, '.', ''),
        'chave_nfe'       => $chaveNFe,
        'obs_raw'         => $campoObs,
    ]);
    exit;
}

/* ════════════ ATUALIZA O SALDO NA TABELA CORRETA (CLUB_WALLETS) ════════════ */
$s_w = $pdo->prepare("SELECT id, saldo FROM club_wallets WHERE company_id=? AND client_id=? LIMIT 1");
$s_w->execute([$companyId, $clientId]);
$wallet = $s_w->fetch(PDO::FETCH_ASSOC);

$saldoTotal = 0;

if (!$wallet) {
    // Cria a carteira se não existir
    $pdo->prepare("INSERT INTO club_wallets (company_id, client_id, saldo, total_ganho) VALUES (?, ?, ?, ?)")
        ->execute([$companyId, $clientId, $cashback, $cashback]);
    $walletId = $pdo->lastInsertId();
    $saldoTotal = $cashback;
} else {
    // Atualiza a carteira existente
    $walletId = $wallet['id'];
    $pdo->prepare("UPDATE club_wallets SET saldo = saldo + ?, total_ganho = total_ganho + ?, updated_at = NOW() WHERE id = ?")
        ->execute([$cashback, $cashback, $walletId]);
    $saldoTotal = (float)$wallet['saldo'] + $cashback;
}

/* ════════════ SALVA O HISTÓRICO NA TABELA CORRETA (CLUB_TRANSACTIONS) ════════════ */
$pdo->prepare("
    INSERT INTO club_transactions
    (company_id, client_id, wallet_id, tipo, valor, descricao, referencia_tipo, expira_em, created_at)
    VALUES (?, ?, ?, 'credito', ?, ?, 'venda', ?, NOW())
")->execute([$companyId, $clientId, $walletId, $cashback, "Cashback automático via NF-e", $expiraEm]);


/* Resposta Final de Sucesso para o Activepieces */
echo json_encode([
    'ok'              => true,
    'cliente'         => $clientNome,
    'primeiro_nome'   => explode(' ', trim($clientNome))[0],
    'telefone'        => $telefone,
    'cashback_gerado' => number_format($cashback,   2, '.', ''),
    'saldo_total'     => number_format($saldoTotal, 2, '.', ''),
    'valor_compra'    => number_format($valorTotal, 2, '.', ''),
    'percentual'      => $pct,
    'chave_nfe'       => $chaveNFe,
    'data_compra'     => $dataEmissao,
]);
exit;


/* ════════════════════════════════════════════════════
   FUNÇÕES AUXILIARES
════════════════════════════════════════════════════ */

function extrair_telefone(string $texto): string {
    if (!$texto) return '';
    $soDigitos = preg_replace('/\D+/', ' ', $texto);
    $blocos    = array_filter(explode(' ', trim($soDigitos)));
    foreach ($blocos as $bloco) {
        $n = $bloco;
        if (strlen($n) === 13 && str_starts_with($n, '55')) $n = substr($n, 2);
        if (strlen($n) === 11 || strlen($n) === 10) return '55' . $n;
    }
    preg_match_all('/\d{10,11}/', preg_replace('/\D/', '', $texto), $m);
    return !empty($m[0]) ? '55' . $m[0][0] : '';
}

function buscar_cliente_por_telefone(PDO $pdo, int $companyId, string $telefone): ?array {
    $digits = preg_replace('/\D/', '', $telefone);
    $sem55  = str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
    $com55  = '55' . $sem55;
    $last8  = substr($sem55, -8);

    $st = $pdo->prepare("
        SELECT id, nome, whatsapp
        FROM clients
        WHERE company_id = ?
          AND (
            REGEXP_REPLACE(whatsapp,'[^0-9]','') = ?
            OR REGEXP_REPLACE(whatsapp,'[^0-9]','') = ?
          )
        LIMIT 1
    ");
    $st->execute([$companyId, $com55, $sem55]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $st2 = $pdo->prepare("
        SELECT id, nome, whatsapp
        FROM clients
        WHERE company_id = ?
          AND RIGHT(REGEXP_REPLACE(whatsapp,'[^0-9]',''), 8) = ?
        LIMIT 1
    ");
    $st2->execute([$companyId, $last8]);
    return $st2->fetch(PDO::FETCH_ASSOC) ?: null;
}

