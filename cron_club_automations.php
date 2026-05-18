<?php
// ============================================================
// cron_club_automations.php — Automações WhatsApp do Clube
// Mesmo padrão do cron_send_reminders.php
//
// Configurar no Coolify / servidor para rodar 1x por dia:
// 0 10 * * * php /app/cron_club_automations.php >> /app/logs/club_cron.log 2>&1
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$pdo = get_pdo();

echo "[" . date('Y-m-d H:i:s') . "] Iniciando automações do Clube...\n";

// ── Busca todas as empresas com clube ativo ──────────────────
$companies = $pdo->query("
    SELECT c.id, c.nome_fantasia, c.whatsapp_principal,
           r.cashback_validade, r.selos_premio, r.voucher_valor,
           r.nome_clube, r.cashback_pct
    FROM companies c
    INNER JOIN club_rules r ON r.company_id = c.id AND r.ativo = 1
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($companies)) {
    echo "Nenhuma empresa com clube ativo.\n";
    exit;
}

// ── Helper: já enviou este tipo hoje para este cliente? ───────
function ja_enviou_hoje(PDO $pdo, int $companyId, int $clientId, string $tipo): bool {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM club_automation_logs
        WHERE company_id=? AND client_id=? AND tipo=?
          AND DATE(created_at)=CURDATE()
    ");
    $s->execute([$companyId, $clientId, $tipo]);
    return (int)$s->fetchColumn() > 0;
}

// ── Helper: registra log ──────────────────────────────────────
function log_automacao(PDO $pdo, int $companyId, int $clientId, string $tipo, string $whats, string $msg, bool $enviado, string $erro = ''): void {
    $pdo->prepare("
        INSERT INTO club_automation_logs (company_id,client_id,tipo,whatsapp,mensagem,enviado,erro)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([$companyId, $clientId, $tipo, $whats, $msg, $enviado ? 1 : 0, $erro]);
}

// ── Helper: primeiro nome ─────────────────────────────────────
function primeiro_nome(string $nome): string {
    return explode(' ', trim($nome))[0];
}

$totalEnviados = 0;
$totalErros    = 0;

foreach ($companies as $company) {
    $cid       = (int)$company['id'];
    $clube     = $company['nome_clube'] ?? 'Clube For Men';
    $validade  = (int)$company['cashback_validade'];
    $selosMeta = (int)$company['selos_premio'];
    $voucherVal= number_format((float)$company['voucher_valor'], 2, ',', '.');

    echo "\n[{$company['nome_fantasia']}]\n";

    // ════════════════════════════════════════════════════════
    // FLUXO 1 — Saldo vencendo em 5 dias
    // ════════════════════════════════════════════════════════
    $vencendo = $pdo->prepare("
        SELECT DISTINCT w.client_id, w.saldo,
               c.nome, c.whatsapp,
               MIN(t.expira_em) as proxima_expiracao
        FROM club_transactions t
        INNER JOIN club_wallets w ON w.client_id=t.client_id AND w.company_id=t.company_id
        INNER JOIN clients c ON c.id=t.client_id
        WHERE t.company_id=?
          AND t.tipo='credito'
          AND t.expira_em = DATE_ADD(CURDATE(), INTERVAL 5 DAY)
          AND w.saldo > 0
        GROUP BY w.client_id, w.saldo, c.nome, c.whatsapp
    ");
    $vencendo->execute([$cid]);
    $clientesVencendo = $vencendo->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientesVencendo as $cli) {
        $clientId = (int)$cli['client_id'];
        $whats    = preg_replace('/\D+/', '', $cli['whatsapp'] ?? '');
        if (strlen($whats) < 8) continue;
        if (ja_enviou_hoje($pdo, $cid, $clientId, 'expiracao_5dias')) continue;

        $saldo = number_format((float)$cli['saldo'], 2, ',', '.');
        $nome  = primeiro_nome($cli['nome']);

        $msg = "⚠️ *{$clube}* — Saldo vencendo!\n\n";
        $msg .= "Oi {$nome}! Seu saldo de *R\${$saldo}* vence em *5 dias*.\n\n";
        $msg .= "Corre lá e aproveita antes de perder! 🔥\n";
        $msg .= "Use na próxima compra na loja.";

        $enviado = send_whatsapp_message($whats, $msg);
        log_automacao($pdo, $cid, $clientId, 'expiracao_5dias', $whats, $msg, $enviado);

        if ($enviado) {
            echo "  ✅ [Vencendo] {$cli['nome']} — R\${$saldo}\n";
            $totalEnviados++;
        } else {
            echo "  ❌ [Vencendo] {$cli['nome']} — falha no envio\n";
            $totalErros++;
        }
    }

    // ════════════════════════════════════════════════════════
    // FLUXO 2 — Falta 1 corte para o prêmio
    // ════════════════════════════════════════════════════════
    $quase = $pdo->prepare("
        SELECT s.client_id, s.selos_ciclo, c.nome, c.whatsapp
        FROM club_stamps s
        INNER JOIN clients c ON c.id=s.client_id
        WHERE s.company_id=? AND s.selos_ciclo=?
    ");
    $quase->execute([$cid, $selosMeta - 1]);
    $clientesQuase = $quase->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientesQuase as $cli) {
        $clientId = (int)$cli['client_id'];
        $whats    = preg_replace('/\D+/', '', $cli['whatsapp'] ?? '');
        if (strlen($whats) < 8) continue;
        if (ja_enviou_hoje($pdo, $cid, $clientId, 'quase_premio')) continue;

        $nome = primeiro_nome($cli['nome']);

        $msg = "✂️ *{$clube}* — Falta só 1 corte!\n\n";
        $msg .= "Oi {$nome}! Você está a *1 corte* de ganhar um voucher de *R\${$voucherVal}* para usar na loja de roupas. 🎁\n\n";
        $msg .= "Agenda hoje e garante o seu prêmio!";

        $enviado = send_whatsapp_message($whats, $msg);
        log_automacao($pdo, $cid, $clientId, 'quase_premio', $whats, $msg, $enviado);

        if ($enviado) {
            echo "  ✅ [Quase prêmio] {$cli['nome']} — {$cli['selos_ciclo']}/{$selosMeta}\n";
            $totalEnviados++;
        } else {
            echo "  ❌ [Quase prêmio] {$cli['nome']} — falha no envio\n";
            $totalErros++;
        }
    }

    // ════════════════════════════════════════════════════════
    // FLUXO 3 — Cliente sumido há 30 dias (sem corte)
    // ════════════════════════════════════════════════════════
    $sumidos = $pdo->prepare("
        SELECT s.client_id, c.nome, c.whatsapp,
               MAX(h.created_at) as ultimo_corte
        FROM club_stamps s
        INNER JOIN club_stamp_history h ON h.stamp_id=s.id
        INNER JOIN clients c ON c.id=s.client_id
        WHERE s.company_id=?
        GROUP BY s.client_id, c.nome, c.whatsapp
        HAVING ultimo_corte < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $sumidos->execute([$cid]);
    $clientesSumidos = $sumidos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientesSumidos as $cli) {
        $clientId = (int)$cli['client_id'];
        $whats    = preg_replace('/\D+/', '', $cli['whatsapp'] ?? '');
        if (strlen($whats) < 8) continue;
        if (ja_enviou_hoje($pdo, $cid, $clientId, 'sumido_30dias')) continue;

        // Só manda 1x por semana para sumidos (evita spam)
        $ultimoLog = $pdo->prepare("
            SELECT MAX(created_at) FROM club_automation_logs
            WHERE company_id=? AND client_id=? AND tipo='sumido_30dias'
        ");
        $ultimoLog->execute([$cid, $clientId]);
        $ultimoEnvio = $ultimoLog->fetchColumn();
        if ($ultimoEnvio && strtotime($ultimoEnvio) > strtotime('-7 days')) continue;

        $nome = primeiro_nome($cli['nome']);

        $msg = "😂 *{$clube}*\n\n";
        $msg .= "Oi {$nome}! Seu barbeiro tá achando que você mudou de cidade... 😅\n\n";
        $msg .= "Faz um tempo que a gente não te vê! Agenda um corte e ainda acumula selos para ganhar desconto na loja. ✂️";

        $enviado = send_whatsapp_message($whats, $msg);
        log_automacao($pdo, $cid, $clientId, 'sumido_30dias', $whats, $msg, $enviado);

        if ($enviado) {
            echo "  ✅ [Sumido] {$cli['nome']} — último corte: {$cli['ultimo_corte']}\n";
            $totalEnviados++;
        } else {
            echo "  ❌ [Sumido] {$cli['nome']} — falha no envio\n";
            $totalErros++;
        }
    }

    // ════════════════════════════════════════════════════════
    // FLUXO 4 — Reativação de compra (90 dias sem comprar)
    // ════════════════════════════════════════════════════════
    $inativos = $pdo->prepare("
        SELECT w.client_id, c.nome, c.whatsapp,
               MAX(t.created_at) as ultima_compra
        FROM club_wallets w
        INNER JOIN club_transactions t ON t.wallet_id=w.id AND t.tipo='credito'
        INNER JOIN clients c ON c.id=w.client_id
        WHERE w.company_id=?
        GROUP BY w.client_id, c.nome, c.whatsapp
        HAVING ultima_compra < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $inativos->execute([$cid]);
    $clientesInativos = $inativos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientesInativos as $cli) {
        $clientId = (int)$cli['client_id'];
        $whats    = preg_replace('/\D+/', '', $cli['whatsapp'] ?? '');
        if (strlen($whats) < 8) continue;
        if (ja_enviou_hoje($pdo, $cid, $clientId, 'reativacao_90dias')) continue;

        // 1x por mês para inativos
        $ultimoLog = $pdo->prepare("
            SELECT MAX(created_at) FROM club_automation_logs
            WHERE company_id=? AND client_id=? AND tipo='reativacao_90dias'
        ");
        $ultimoLog->execute([$cid, $clientId]);
        $ultimoEnvio = $ultimoLog->fetchColumn();
        if ($ultimoEnvio && strtotime($ultimoEnvio) > strtotime('-30 days')) continue;

        $nome = primeiro_nome($cli['nome']);
        $cashbackPct = (int)$company['cashback_pct'];

        $msg = "👔 *{$clube}*\n\n";
        $msg .= "Oi {$nome}! Chegou coisa nova no estilo que você curte. 🔥\n\n";
        $msg .= "Lembra que toda compra gera *{$cashbackPct}% de cashback* para você usar depois?\n\n";
        $msg .= "Passa lá ou fala com a gente pelo WhatsApp!";

        $enviado = send_whatsapp_message($whats, $msg);
        log_automacao($pdo, $cid, $clientId, 'reativacao_90dias', $whats, $msg, $enviado);

        if ($enviado) {
            echo "  ✅ [Reativação] {$cli['nome']} — última compra: {$cli['ultima_compra']}\n";
            $totalEnviados++;
        } else {
            echo "  ❌ [Reativação] {$cli['nome']} — falha no envio\n";
            $totalErros++;
        }
    }

    // ════════════════════════════════════════════════════════
    // FLUXO 5 — Expirar cashbacks vencidos (silencioso)
    // ════════════════════════════════════════════════════════
    try {
        // Busca transações vencidas que ainda não foram expiradas
        $exp = $pdo->prepare("
            SELECT t.id, t.wallet_id, t.client_id, t.valor,
                   c.nome, c.whatsapp
            FROM club_transactions t
            INNER JOIN clients c ON c.id=t.client_id
            WHERE t.company_id=?
              AND t.tipo='credito'
              AND t.expira_em < CURDATE()
              AND t.id NOT IN (
                  SELECT referencia_id FROM club_transactions
                  WHERE company_id=? AND tipo='expiracao' AND referencia_id IS NOT NULL
              )
        ");
        $exp->execute([$cid, $cid]);
        $expirados = $exp->fetchAll(PDO::FETCH_ASSOC);

        foreach ($expirados as $e) {
            // Debita da carteira
            $pdo->prepare("
                UPDATE club_wallets
                SET saldo = GREATEST(0, saldo - ?), updated_at=NOW()
                WHERE id=?
            ")->execute([$e['valor'], $e['wallet_id']]);

            // Registra expiração
            $pdo->prepare("
                INSERT INTO club_transactions
                (company_id,client_id,wallet_id,tipo,valor,descricao,referencia_id,referencia_tipo)
                VALUES (?,?,?,'expiracao',?,?,?,'transaction')
            ")->execute([
                $cid, $e['client_id'], $e['wallet_id'],
                $e['valor'], 'Cashback expirado automaticamente', $e['id']
            ]);

            echo "  🗑️ [Expirado] {$e['nome']} — R$" . number_format($e['valor'],2,',','.') . "\n";
        }
    } catch (\Exception $ex) {
        echo "  ⚠️ Erro ao expirar cashbacks: " . $ex->getMessage() . "\n";
    }
}

echo "\n[" . date('Y-m-d H:i:s') . "] Concluído. Enviados: {$totalEnviados} | Erros: {$totalErros}\n";