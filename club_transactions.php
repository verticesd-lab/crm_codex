<?php
// Conferência e correção manual dos cashbacks recebidos do sistema NEO.
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();
require_admin();

$pdo       = get_pdo();
$companyId = (int)current_company_id();
$userId    = (int)($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['club_transactions_csrf'])) {
    $_SESSION['club_transactions_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['club_transactions_csrf'];

function neo_date_param(string $value, string $fallback): string {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

function neo_return_query(array $source): string {
    $allowed = [];
    foreach (['q', 'de', 'ate', 'suspeitas'] as $key) {
        if (isset($source[$key]) && is_scalar($source[$key])) {
            $allowed[$key] = substr((string)$source[$key], 0, 120);
        }
    }
    return http_build_query($allowed);
}

function neo_original_sale(array $transaction, float $cashbackPct): array {
    $description = (string)($transaction['descricao'] ?? '');
    if (preg_match('/venda de R\$\s*([0-9.]+,[0-9]{2})/u', $description, $match)) {
        $normalized = str_replace(['.', ','], ['', '.'], $match[1]);
        return ['value' => (float)$normalized, 'estimated' => false];
    }
    if ($cashbackPct > 0) {
        return ['value' => round((float)$transaction['valor'] * 100 / $cashbackPct, 2), 'estimated' => true];
    }
    return ['value' => null, 'estimated' => true];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnQuery = neo_return_query($_POST);

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        flash('club_transactions_error', 'A sessão de segurança expirou. Recarregue a página e tente novamente.');
        redirect('club_transactions.php' . ($returnQuery ? '?' . $returnQuery : ''));
    }

    $reason = trim(substr((string)($_POST['motivo'] ?? ''), 0, 250));
    $rawIds = $_POST['transaction_ids'] ?? [];
    if (!is_array($rawIds)) $rawIds = [$rawIds];
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn($id) => $id > 0)));

    if (!$ids || count($ids) > 100) {
        flash('club_transactions_error', 'Selecione de 1 a 100 movimentações para corrigir.');
        redirect('club_transactions.php' . ($returnQuery ? '?' . $returnQuery : ''));
    }
    if (strlen($reason) < 5) {
        flash('club_transactions_error', 'Informe um motivo com pelo menos 5 caracteres para a auditoria.');
        redirect('club_transactions.php' . ($returnQuery ? '?' . $returnQuery : ''));
    }

    try {
        $pdo->beginTransaction();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$companyId], $ids);

        $stmt = $pdo->prepare("
            SELECT t.*, w.saldo, w.total_ganho
            FROM club_transactions t
            INNER JOIN club_wallets w
                ON w.id=t.wallet_id AND w.company_id=t.company_id AND w.client_id=t.client_id
            WHERE t.company_id=? AND t.id IN ($marks)
            FOR UPDATE
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== count($ids)) {
            throw new RuntimeException('Uma ou mais movimentações não foram encontradas nesta empresa.');
        }

        foreach ($rows as $row) {
            $isNeo = $row['tipo'] === 'credito'
                && $row['referencia_tipo'] === 'venda'
                && str_starts_with((string)$row['descricao'], 'Cashback automatico NEO');
            if (!$isNeo) {
                throw new RuntimeException('A movimentação #' . $row['id'] . ' não é um crédito automático do NEO.');
            }
        }

        $expirationStmt = $pdo->prepare("
            SELECT referencia_id, COUNT(*) AS quantidade, SUM(valor) AS valor
            FROM club_transactions
            WHERE company_id=? AND tipo='expiracao' AND referencia_tipo='transaction'
              AND referencia_id IN ($marks)
            GROUP BY referencia_id
            FOR UPDATE
        ");
        $expirationStmt->execute($params);
        $expirations = [];
        foreach ($expirationStmt->fetchAll(PDO::FETCH_ASSOC) as $expiration) {
            $expirations[(int)$expiration['referencia_id']] = $expiration;
        }

        $today = date('Y-m-d');
        $walletAdjustments = [];
        foreach ($rows as $row) {
            $transactionId = (int)$row['id'];
            $walletId      = (int)$row['wallet_id'];
            $value         = (float)$row['valor'];
            $expiration    = $expirations[$transactionId] ?? null;

            if ($expiration) {
                if ((int)$expiration['quantidade'] !== 1 || abs((float)$expiration['valor'] - $value) > 0.009) {
                    throw new RuntimeException('A movimentação #' . $transactionId . ' possui uma expiração inconsistente e precisa de análise técnica.');
                }
                $balanceReversal = 0.0;
            } elseif (!empty($row['expira_em']) && substr((string)$row['expira_em'], 0, 10) < $today) {
                throw new RuntimeException('A movimentação #' . $transactionId . ' já venceu, mas não possui registro de expiração. Ela precisa de análise técnica antes da exclusão.');
            } else {
                $balanceReversal = $value;
            }

            if (!isset($walletAdjustments[$walletId])) {
                $walletAdjustments[$walletId] = [
                    'saldo_atual' => (float)$row['saldo'],
                    'saldo'       => 0.0,
                    'ganho'       => 0.0,
                ];
            }
            $walletAdjustments[$walletId]['saldo'] += $balanceReversal;
            $walletAdjustments[$walletId]['ganho'] += $value;
        }

        foreach ($walletAdjustments as $walletId => $adjustment) {
            if ($adjustment['saldo_atual'] + 0.009 < $adjustment['saldo']) {
                throw new RuntimeException('Não foi possível excluir: parte deste cashback já foi utilizada pelo cliente. Confira os resgates antes de corrigir.');
            }
            $update = $pdo->prepare("
                UPDATE club_wallets
                SET saldo=GREATEST(0, saldo-?), total_ganho=GREATEST(0, total_ganho-?), updated_at=NOW()
                WHERE id=? AND company_id=?
            ");
            $update->execute([$adjustment['saldo'], $adjustment['ganho'], $walletId, $companyId]);
        }

        $deleteExpirations = $pdo->prepare("
            DELETE FROM club_transactions
            WHERE company_id=? AND tipo='expiracao' AND referencia_tipo='transaction'
              AND referencia_id IN ($marks)
        ");
        $deleteExpirations->execute($params);

        $deleteCredits = $pdo->prepare("
            DELETE FROM club_transactions
            WHERE company_id=? AND id IN ($marks)
        ");
        $deleteCredits->execute($params);
        if ($deleteCredits->rowCount() !== count($ids)) {
            throw new RuntimeException('Nem todas as movimentações puderam ser excluídas. Nenhuma alteração foi aplicada.');
        }

        $audit = [
            'motivo' => $reason,
            'movimentacoes' => array_map(static function ($row) {
                return [
                    'id' => (int)$row['id'],
                    'cliente_id' => (int)$row['client_id'],
                    'venda_neo' => (string)$row['referencia_id'],
                    'cashback' => (float)$row['valor'],
                    'data' => (string)$row['created_at'],
                ];
            }, $rows),
        ];
        log_action($pdo, $companyId, $userId, 'club_neo_correcao', json_encode($audit, JSON_UNESCAPED_UNICODE));
        $pdo->commit();

        flash('club_transactions_success', count($ids) . ' movimentação(ões) do NEO corrigida(s). Saldo e total ganho foram recalculados.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[club_transactions] Falha na correcao: ' . $e->getMessage());
        flash('club_transactions_error', $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a correção. Tente novamente.');
    }

    redirect('club_transactions.php' . ($returnQuery ? '?' . $returnQuery : ''));
}

$today      = date('Y-m-d');
$defaultDe  = date('Y-m-d', strtotime('-30 days'));
$q          = trim(substr((string)($_GET['q'] ?? ''), 0, 120));
$de         = neo_date_param((string)($_GET['de'] ?? ''), $defaultDe);
$ate        = neo_date_param((string)($_GET['ate'] ?? ''), $today);
$suspeitas  = isset($_GET['suspeitas']) && $_GET['suspeitas'] === '1';

if ($de > $ate) [$de, $ate] = [$ate, $de];

$where = [
    't.company_id=?',
    "t.tipo='credito'",
    "t.referencia_tipo='venda'",
    "t.descricao LIKE 'Cashback automatico NEO%'",
    't.created_at >= ?',
    't.created_at < DATE_ADD(?, INTERVAL 1 DAY)',
];
$queryParams = [$companyId, $de, $ate];

if ($q !== '') {
    $where[] = "(c.nome LIKE ? OR c.whatsapp LIKE ? OR c.telefone_principal LIKE ? OR CAST(t.referencia_id AS CHAR) LIKE ?)";
    $term = '%' . $q . '%';
    array_push($queryParams, $term, $term, $term, $term);
}

$nearbySql = "
    SELECT COUNT(*)
    FROM club_transactions nx
    WHERE nx.company_id=t.company_id AND nx.client_id=t.client_id AND nx.id<>t.id
      AND nx.tipo='credito' AND nx.referencia_tipo='venda'
      AND nx.descricao LIKE 'Cashback automatico NEO%'
      AND nx.created_at BETWEEN DATE_SUB(t.created_at, INTERVAL 5 MINUTE)
                            AND DATE_ADD(t.created_at, INTERVAL 5 MINUTE)
";
if ($suspeitas) $where[] = "EXISTS ($nearbySql)";

$sql = "
    SELECT t.*, c.nome AS cliente_nome, c.whatsapp, c.telefone_principal,
           w.saldo AS saldo_atual, ($nearbySql) AS proximas
    FROM club_transactions t
    INNER JOIN clients c ON c.id=t.client_id AND c.company_id=t.company_id
    INNER JOIN club_wallets w ON w.id=t.wallet_id AND w.company_id=t.company_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 100
";
$listStmt = $pdo->prepare($sql);
$listStmt->execute($queryParams);
$transactions = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$ruleStmt = $pdo->prepare('SELECT cashback_pct FROM club_rules WHERE company_id=? LIMIT 1');
$ruleStmt->execute([$companyId]);
$cashbackPct = (float)($ruleStmt->fetchColumn() ?: 0);

$totalCashback = array_sum(array_map(static fn($row) => (float)$row['valor'], $transactions));
$success = get_flash('club_transactions_success');
$error   = get_flash('club_transactions_error');

include __DIR__ . '/views/partials/header.php';
?>
<style>
.neo-wrap{max-width:1180px}.neo-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.1rem}.neo-title{font-size:1.25rem;font-weight:800;color:#0f172a}.neo-sub{font-size:.78rem;color:#64748b;margin-top:.2rem}.neo-back{padding:.55rem .9rem;border:1px solid #e2e8f0;border-radius:9px;text-decoration:none;color:#475569;background:#fff;font-size:.8rem;font-weight:700}.neo-alert{padding:.8rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.82rem}.neo-alert.ok{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.neo-alert.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.neo-help{background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:.85rem 1rem;color:#854d0e;font-size:.78rem;line-height:1.45;margin-bottom:1rem}.neo-filter{display:grid;grid-template-columns:minmax(220px,1fr) 150px 150px auto auto;gap:.65rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.9rem;margin-bottom:1rem;align-items:end}.neo-field label{display:block;font-size:.65rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.3rem}.neo-inp{width:100%;box-sizing:border-box;padding:.55rem .65rem;border:1px solid #cbd5e1;border-radius:8px;background:#fff}.neo-check{display:flex;align-items:center;gap:.35rem;font-size:.75rem;color:#475569;padding-bottom:.55rem;white-space:nowrap}.neo-btn{border:0;border-radius:8px;padding:.58rem .9rem;background:#4f46e5;color:#fff;font-weight:700;cursor:pointer}.neo-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.neo-summary{display:flex;gap:1.2rem;padding:.8rem 1rem;border-bottom:1px solid #e2e8f0;font-size:.78rem;color:#64748b}.neo-summary strong{color:#0f172a}.neo-table-wrap{overflow:auto}.neo-table{width:100%;border-collapse:collapse;font-size:.78rem}.neo-table th{padding:.65rem;text-align:left;background:#f8fafc;color:#64748b;font-size:.65rem;text-transform:uppercase;white-space:nowrap}.neo-table td{padding:.65rem;border-top:1px solid #f1f5f9;vertical-align:middle}.neo-table tr.flag{background:#fff7ed}.neo-name{font-weight:700;color:#0f172a;white-space:nowrap}.neo-muted{font-size:.68rem;color:#94a3b8;margin-top:.15rem}.neo-id{font-family:monospace;color:#475569;white-space:nowrap}.neo-badge{display:inline-block;padding:.2rem .4rem;border-radius:999px;background:#ffedd5;color:#c2410c;font-size:.65rem;font-weight:700;white-space:nowrap}.neo-actions{padding:1rem;border-top:1px solid #e2e8f0;background:#f8fafc;display:grid;grid-template-columns:1fr auto;gap:.7rem;align-items:end}.neo-danger{background:#dc2626}.neo-empty{text-align:center;padding:2.5rem;color:#94a3b8}.neo-select-all{cursor:pointer}@media(max-width:850px){.neo-filter{grid-template-columns:1fr 1fr}.neo-filter .wide{grid-column:1/-1}.neo-actions{grid-template-columns:1fr}.neo-summary{flex-wrap:wrap}}
</style>

<div class="neo-wrap">
    <div class="neo-head">
        <div>
            <h1 class="neo-title">🔎 Conferência de vendas NEO</h1>
            <p class="neo-sub">Revise e corrija créditos automáticos incorretos sem alterar vendas do caixa.</p>
        </div>
        <a class="neo-back" href="club.php">← Voltar ao painel</a>
    </div>

    <?php if ($success): ?><div class="neo-alert ok">✅ <?= sanitize($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="neo-alert err">⚠️ <?= sanitize($error) ?></div><?php endif; ?>

    <div class="neo-help">
        Linhas em laranja indicam que o mesmo cliente recebeu outro crédito NEO em até 5 minutos. Isso é apenas um alerta para conferência, não prova que a venda esteja errada. A exclusão fica registrada na auditoria do sistema.
    </div>

    <form method="GET" class="neo-filter">
        <div class="neo-field wide">
            <label>Cliente, telefone ou ID da venda</label>
            <input class="neo-inp" name="q" value="<?= sanitize($q) ?>" placeholder="Ex.: Janaina ou DB_12345">
        </div>
        <div class="neo-field"><label>De</label><input class="neo-inp" type="date" name="de" value="<?= sanitize($de) ?>"></div>
        <div class="neo-field"><label>Ate</label><input class="neo-inp" type="date" name="ate" value="<?= sanitize($ate) ?>"></div>
        <label class="neo-check"><input type="checkbox" name="suspeitas" value="1" <?= $suspeitas ? 'checked' : '' ?>> Só alertas</label>
        <button class="neo-btn" type="submit">Buscar</button>
    </form>

    <form method="POST" id="neo-correction-form" class="neo-card">
        <input type="hidden" name="csrf_token" value="<?= sanitize($csrfToken) ?>">
        <input type="hidden" name="q" value="<?= sanitize($q) ?>">
        <input type="hidden" name="de" value="<?= sanitize($de) ?>">
        <input type="hidden" name="ate" value="<?= sanitize($ate) ?>">
        <input type="hidden" name="suspeitas" value="<?= $suspeitas ? '1' : '0' ?>">

        <div class="neo-summary">
            <span><strong><?= count($transactions) ?></strong> lançamento(s) exibido(s)</span>
            <span><strong><?= format_currency($totalCashback) ?></strong> em cashback na lista</span>
            <span>Máximo de 100 resultados por busca</span>
        </div>

        <?php if (!$transactions): ?>
            <div class="neo-empty">Nenhuma movimentação automática do NEO encontrada neste filtro.</div>
        <?php else: ?>
        <div class="neo-table-wrap">
            <table class="neo-table">
                <thead><tr>
                    <th><input class="neo-select-all" type="checkbox" aria-label="Selecionar todas"></th>
                    <th>Data</th><th>Cliente</th><th>Venda NEO</th><th>Valor da venda</th><th>Cashback</th><th>Saldo atual</th><th>Conferência</th>
                </tr></thead>
                <tbody>
                <?php foreach ($transactions as $transaction):
                    $flag = (int)$transaction['proximas'] > 0;
                    $sale = neo_original_sale($transaction, $cashbackPct);
                ?>
                <tr class="<?= $flag ? 'flag' : '' ?>">
                    <td><input class="neo-row-check" type="checkbox" name="transaction_ids[]" value="<?= (int)$transaction['id'] ?>" aria-label="Selecionar movimentação <?= (int)$transaction['id'] ?>"></td>
                    <td style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?><div class="neo-muted">#<?= (int)$transaction['id'] ?></div></td>
                    <td><div class="neo-name"><?= sanitize($transaction['cliente_nome']) ?></div><div class="neo-muted"><?= sanitize($transaction['whatsapp'] ?: $transaction['telefone_principal']) ?></div></td>
                    <td><div class="neo-id"><?= sanitize($transaction['referencia_id'] ?: 'sem ID') ?></div><div class="neo-muted"><?= sanitize($transaction['descricao']) ?></div></td>
                    <td style="white-space:nowrap"><?php if ($sale['value'] !== null): ?><?= format_currency($sale['value']) ?><div class="neo-muted"><?= $sale['estimated'] ? 'estimado pela regra atual' : 'informado pelo NEO' ?></div><?php else: ?><span class="neo-muted">Não disponível</span><?php endif; ?></td>
                    <td style="font-weight:800;color:#16a34a;white-space:nowrap">+<?= format_currency($transaction['valor']) ?></td>
                    <td style="white-space:nowrap"><?= format_currency($transaction['saldo_atual']) ?></td>
                    <td><?php if ($flag): ?><span class="neo-badge">⚠ <?= (int)$transaction['proximas'] + 1 ?> créditos em 5 min</span><?php else: ?><span class="neo-muted">Sem alerta</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="neo-actions">
            <div class="neo-field">
                <label>Motivo da correção (obrigatório)</label>
                <input class="neo-inp" name="motivo" maxlength="250" required minlength="5" placeholder="Ex.: venda inexistente no PDV NEO">
            </div>
            <button class="neo-btn neo-danger" type="submit">🗑 Corrigir selecionadas</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('neo-correction-form');
    if (!form) return;
    const selectAll = form.querySelector('.neo-select-all');
    const checks = Array.from(form.querySelectorAll('.neo-row-check'));
    if (selectAll) selectAll.addEventListener('change', () => checks.forEach(check => { check.checked = selectAll.checked; }));
    form.addEventListener('submit', function (event) {
        const selected = checks.filter(check => check.checked).length;
        if (!selected) {
            event.preventDefault();
            alert('Selecione pelo menos uma movimentação.');
            return;
        }
        if (!confirm('Confirma a exclusão de ' + selected + ' movimentação(ões) do NEO? O saldo do cliente será corrigido e a ação ficará na auditoria.')) {
            event.preventDefault();
        }
    });
})();
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
