<?php
/**
 * reativacao_api.php
 * ─────────────────────────────────────────────────────────────────
 * Endpoints AJAX para o sistema de reativação de clientes.
 * Todos os endpoints retornam JSON.
 *
 * Actions:
 *   setup_tables     — cria tabelas se não existirem
 *   get_stats        — dashboard KPIs
 *   get_eligible     — lista clientes elegíveis com filtros
 *   create_lote      — cria um lote para revisão
 *   get_lote         — detalhes de um lote
 *   get_lotes        — histórico de lotes
 *   send_next        — envia próxima mensagem pendente do lote ativo
 *   cancel_lote      — cancela lote
 *   get_clients_by_status — segmentos por status de reativação
 *   update_client_status  — atualiza status manualmente
 *   mark_responded   — webhook interno: cliente respondeu
 * ─────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

// Auth básica
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado.']);
    exit;
}

$pdo       = get_pdo();
$companyId = current_company_id();
$userId    = (int)($_SESSION['user_id'] ?? 0);

if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Empresa não encontrada.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ═══════════════════════════════════════════════════
   MENSAGENS — 3 contextos × variações
═══════════════════════════════════════════════════ */
/* Mensagens padrão — usadas como fallback quando não há customização no banco */
function get_default_messages(): array {
    return [
        'pdv' => [
            1 => [
                "Oi {nome}! Tudo bem? 😊\n\nAqui é da Formen Store. Faz um tempinho que você não passa por aqui — qualquer coisa que precisar é só chamar!",
                "{nome}! Sumido(a) hein 😄\n\nAqui é a equipe da Formen. Lançamos algumas novidades essa semana, se quiser dar uma olhada é só falar!",
                "Oi {nome}, tudo certo?\n\nAqui é da Formen Store, lembrando que estamos por aqui sempre que precisar 🙂\n\nAbraço!",
            ],
            2 => [
                "Oi {nome}! Passando só pra dizer que ainda estamos por aqui caso precise de qualquer coisa 😊\n\nFormen Store — pode chamar!",
                "{nome}, oi! Uma última passagem aqui pra dizer que a gente não esqueceu de você 😄\n\nQualquer coisa é só dar um oi!",
            ],
        ],
        'barbearia' => [
            1 => [
                "Oi {nome}! Tudo bem? ✂️\n\nAqui é da Formen Barbearia. Faz um tempo que você não passa por aqui — se quiser agendar é só mandar mensagem!",
                "{nome}! Sumido(a) por aqui né 😄\n\nAqui é a equipe da Formen. Sua agenda está em aberto, qualquer horário é só chamar!",
                "Oi {nome}, tudo certo?\n\nAqui é da Formen Barbearia 🙂 Sempre que precisar de um horário é só falar, temos disponibilidade essa semana!",
            ],
            2 => [
                "Oi {nome}! Passando só pra dizer que a Formen Barbearia ainda está por aqui sempre que você precisar ✂️\n\nAbraço!",
                "{nome}, oi! Uma última passagem aqui — qualquer horário que precisar pode chamar 😊",
            ],
        ],
        'whatsapp' => [
            1 => [
                "Oi {nome}! Tudo bem? 😊\n\nAqui é da Formen. Faz um tempinho que não conversamos — qualquer coisa que precisar pode chamar!",
                "{nome}! Sumido(a) 😄\n\nAqui é a equipe da Formen, passando pra dizer que estamos por aqui! Qualquer dúvida ou pedido é só falar.",
                "Oi {nome}, tudo certo?\n\nAqui é da Formen 🙂 Lembrando que estamos por aqui sempre que precisar!\n\nAbraço!",
            ],
            2 => [
                "Oi {nome}! Passando só pra dizer que a Formen ainda está por aqui caso precise de qualquer coisa 😊",
                "{nome}, oi! Última mensagem aqui — se um dia precisar de algo é só chamar, tá? Abraço! 😄",
            ],
        ],
    ];
}

/* Carrega mensagens: banco primeiro, fallback nas defaults */
function get_messages(?PDO $pdo = null, ?int $companyId = null): array {
    $defaults = get_default_messages();
    if (!$pdo || !$companyId) return $defaults;
    try {
        $stmt = $pdo->prepare("SELECT contexto, tentativa, variacao_idx, mensagem FROM reativacao_mensagens WHERE company_id = ? ORDER BY contexto, tentativa, variacao_idx");
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) return $defaults;
        // Reconstrói estrutura a partir do banco
        $msgs = $defaults;
        foreach ($rows as $r) {
            $ctx  = $r['contexto'];
            $tent = (int)$r['tentativa'];
            $idx  = (int)$r['variacao_idx'];
            if (isset($msgs[$ctx][$tent])) {
                $msgs[$ctx][$tent][$idx] = $r['mensagem'];
            }
        }
        return $msgs;
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * Detecta o contexto de reativação do cliente com base nas tags/histórico
 */
function detect_context(array $client): string {
    $tags = strtolower((string)($client['tags'] ?? ''));
    if (str_contains($tags, 'barbearia')) return 'barbearia';
    if (str_contains($tags, 'pdv') || str_contains($tags, 'reativacao')) return 'pdv';
    return 'whatsapp';
}

/**
 * Monta a mensagem com variação e substitui {nome}
 */
function build_message(string $context, int $tentativa, string $nome, int $varIdx = -1, ?PDO $pdo = null, ?int $companyId = null): string {
    $msgs = get_messages($pdo, $companyId);
    $pool = $msgs[$context][$tentativa] ?? $msgs['whatsapp'][1];
    $idx  = $varIdx >= 0 ? ($varIdx % count($pool)) : array_rand($pool);
    $msg  = $pool[$idx];
    $firstName = explode(' ', trim($nome))[0];
    return str_replace('{nome}', $firstName, $msg);
}

/**
 * Retorna configuração da Evolution API
 */
function get_evolution_config(?PDO $pdo = null, ?int $companyId = null): array {
    // 1. Tenta constantes / env
    $base     = defined('EVOLUTION_API_URL')  ? EVOLUTION_API_URL  : (getenv('EVOLUTION_API_URL')  ?: '');
    $key      = defined('EVOLUTION_API_KEY')  ? EVOLUTION_API_KEY  : (getenv('EVOLUTION_API_KEY')  ?: '');
    $instance = defined('EVOLUTION_INSTANCE') ? EVOLUTION_INSTANCE : (getenv('EVOLUTION_INSTANCE') ?: '');

    // 2. Fallback: lê da tabela companies (colunas opcionais)
    if ((!$base || !$key || !$instance) && $pdo && $companyId) {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
            $sel  = [];
            if (in_array('evolution_api_url',  $cols)) $sel[] = 'evolution_api_url';
            if (in_array('evolution_api_key',  $cols)) $sel[] = 'evolution_api_key';
            if (in_array('evolution_instance', $cols)) $sel[] = 'evolution_instance';
            if ($sel) {
                $row = $pdo->prepare('SELECT ' . implode(',', $sel) . ' FROM companies WHERE id=? LIMIT 1');
                $row->execute([$companyId]);
                $r = $row->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    if (!$base     && !empty($r['evolution_api_url']))  $base     = $r['evolution_api_url'];
                    if (!$key      && !empty($r['evolution_api_key']))  $key      = $r['evolution_api_key'];
                    if (!$instance && !empty($r['evolution_instance'])) $instance = $r['evolution_instance'];
                }
            }
        } catch (Throwable $e) {}
    }

    return ['base' => rtrim($base, '/'), 'key' => $key, 'instance' => $instance];
}

/**
 * Envia mensagem via Evolution API
 * Retorna ['ok'=>true] ou ['ok'=>false,'error'=>'msg']
 */
function send_whatsapp(string $number, string $text, ?PDO $pdo = null, ?int $companyId = null): array {
    $cfg = get_evolution_config($pdo, $companyId);
    if (!$cfg['base'] || !$cfg['key'] || !$cfg['instance']) {
        return ['ok' => false, 'error' => 'Evolution API não configurada.'];
    }

    // Normaliza número: apenas dígitos, garante DDI 55
    $digits = preg_replace('/\D/', '', $number);
    if (!str_starts_with($digits, '55')) $digits = '55' . $digits;

    $url = "{$cfg['base']}/message/sendText/{$cfg['instance']}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $cfg['key'],
        ],
        CURLOPT_POSTFIELDS      => json_encode([
            'number' => $digits,
            'text'   => $text,
        ]),
        CURLOPT_TIMEOUT         => 20,
        CURLOPT_FOLLOWLOCATION  => true,   // segue redirects (307/301)
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_SSL_VERIFYPEER  => false,  // evita erro de cert em alguns hosts
    ]);

    $resp    = curl_exec($ch);
    $httpCode= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'error' => 'cURL: ' . $curlErr];
    if ($httpCode >= 200 && $httpCode < 300) return ['ok' => true, 'response' => $resp];
    return ['ok' => false, 'error' => "HTTP {$httpCode}: " . $resp];
}

/* ═══════════════════════════════════════════════════
   AUTO-MIGRATE: garante colunas em tempo de execução
═══════════════════════════════════════════════════ */
try {
    // fetch() é confiável em todos os drivers PDO/MySQL, rowCount() não é
    $tableExists = $pdo->query("SHOW TABLES LIKE 'reativacao_envios'")->fetch();
    if ($tableExists) {
        $eCols = $pdo->query('SHOW COLUMNS FROM reativacao_envios')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tentativa',    $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN tentativa    TINYINT DEFAULT 1 AFTER mensagem");
        if (!in_array('respondeu_em', $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN respondeu_em DATETIME NULL");
        if (!in_array('erro_msg',     $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN erro_msg     TEXT NULL");
    }
} catch (Throwable $_mig) {}

// Adiciona coluna validade em reativacao_mensagens
try {
    $rmCols = $pdo->query('SHOW COLUMNS FROM reativacao_mensagens')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('validade', $rmCols)) {
        $pdo->exec("ALTER TABLE reativacao_mensagens ADD COLUMN validade VARCHAR(255) NULL AFTER mensagem");
    }
} catch (Throwable $e) {}

/* ═══════════════════════════════════════════════════
   SETUP TABLES
═══════════════════════════════════════════════════ */
// Controle de cooldown pos-barbearia
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_barbearia_cooldown (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        company_id  INT NOT NULL,
        client_id   INT NOT NULL,
        whatsapp    VARCHAR(30) NOT NULL,
        lote_id     INT NOT NULL,
        enviado_em  DATETIME NOT NULL DEFAULT NOW(),
        expira_em   DATETIME NOT NULL,
        UNIQUE KEY uq_company_client (company_id, client_id),
        INDEX idx_expira (expira_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Tabela de cooldown diário de promoções
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS promocoes_cooldown (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        whatsapp   VARCHAR(30) NOT NULL,
        lote_id    INT NOT NULL,
        enviado_em DATE NOT NULL DEFAULT (CURDATE()),
        UNIQUE KEY uq_promo_dia (company_id, whatsapp, enviado_em),
        INDEX idx_company_data (company_id, enviado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Tabela de mensagens de promoção
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS promocoes_mensagens (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        titulo     VARCHAR(120) NOT NULL,
        mensagem   TEXT NOT NULL,
        validade   VARCHAR(255) NULL,
        ativa      TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT NOW(),
        updated_at DATETIME DEFAULT NOW(),
        INDEX idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

if ($action === 'setup_tables') {
    $errors = [];

    // Colunas na tabela clients
    $clientCols = ['reativ_status','reativ_ultimo_envio','reativ_tentativas'];
    try {
        $existing = $pdo->query('SHOW COLUMNS FROM clients')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('reativ_status', $existing)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN reativ_status VARCHAR(30) DEFAULT 'elegivel'");
        }
        if (!in_array('reativ_ultimo_envio', $existing)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN reativ_ultimo_envio DATETIME NULL");
        }
        if (!in_array('reativ_tentativas', $existing)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN reativ_tentativas TINYINT DEFAULT 0");
        }
    } catch(Throwable $e) { $errors[] = 'clients: ' . $e->getMessage(); }

    // Tabela de lotes
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reativacao_lotes (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id     INT UNSIGNED NOT NULL,
            criado_em      DATETIME NOT NULL,
            iniciado_em    DATETIME NULL,
            concluido_em   DATETIME NULL,
            status         VARCHAR(20) DEFAULT 'aguardando',
            contexto       VARCHAR(20) DEFAULT 'misto',
            total_clientes INT DEFAULT 0,
            enviados       INT DEFAULT 0,
            erros          INT DEFAULT 0,
            mensagem_idx   TINYINT DEFAULT 0,
            criado_por     INT UNSIGNED NULL,
            observacoes    TEXT NULL,
            INDEX idx_company (company_id),
            INDEX idx_status  (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e) { $errors[] = 'reativacao_lotes: ' . $e->getMessage(); }

    // Tabela de envios individuais
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reativacao_envios (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            lote_id      INT UNSIGNED NOT NULL,
            client_id    INT UNSIGNED NOT NULL,
            company_id   INT UNSIGNED NOT NULL,
            whatsapp     VARCHAR(30) NOT NULL,
            nome         VARCHAR(120) NOT NULL,
            contexto     VARCHAR(20) DEFAULT 'whatsapp',
            mensagem     TEXT NOT NULL,
            tentativa    TINYINT DEFAULT 1,
            status       VARCHAR(20) DEFAULT 'pendente',
            enviado_em   DATETIME NULL,
            respondeu_em DATETIME NULL,
            erro_msg     TEXT NULL,
            INDEX idx_lote    (lote_id),
            INDEX idx_client  (client_id),
            INDEX idx_company (company_id),
            INDEX idx_status  (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e) { $errors[] = 'reativacao_envios: ' . $e->getMessage(); }

    // Garante colunas que podem estar faltando em tabelas criadas em versões anteriores
    try {
        $eCols = $pdo->query('SHOW COLUMNS FROM reativacao_envios')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tentativa',    $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN tentativa    TINYINT DEFAULT 1 AFTER mensagem");
        if (!in_array('respondeu_em', $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN respondeu_em DATETIME NULL");
        if (!in_array('erro_msg',     $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN erro_msg     TEXT NULL");
    } catch(Throwable $e) { $errors[] = 'reativacao_envios_cols: ' . $e->getMessage(); }

    // Tabela de mensagens customizadas
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reativacao_mensagens (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id   INT UNSIGNED NOT NULL,
            contexto     VARCHAR(20) NOT NULL,
            tentativa    TINYINT NOT NULL,
            variacao_idx TINYINT NOT NULL,
            mensagem     TEXT NOT NULL,
            validade     VARCHAR(255) NULL,
            updated_at   DATETIME NOT NULL,
            UNIQUE KEY uq_msg (company_id, contexto, tentativa, variacao_idx),
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e) { $errors[] = 'reativacao_mensagens: ' . $e->getMessage(); }

    echo json_encode(['ok' => empty($errors), 'errors' => $errors]);
    exit;
}

/* ═══════════════════════════════════════════════════
   GET STATS
═══════════════════════════════════════════════════ */
if ($action === 'get_stats') {
    $stats = [];
    try {
        $rows = $pdo->prepare("
            SELECT reativ_status, COUNT(*) as total
            FROM clients
            WHERE company_id = ?
              AND whatsapp IS NOT NULL AND whatsapp != ''
            GROUP BY reativ_status
        ");
        $rows->execute([$companyId]);
        $byStatus = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byStatus[$r['reativ_status'] ?? 'elegivel'] = (int)$r['total'];
        }

        $stats['total_base']        = array_sum($byStatus);
        $stats['elegiveis']         = ($byStatus['elegivel']         ?? 0) + ($byStatus['']  ?? 0) + ($byStatus[null] ?? 0);
        $stats['lote_1_enviado']    = $byStatus['lote_enviado_1']    ?? 0;
        $stats['responderam']       = ($byStatus['respondeu_1']      ?? 0) + ($byStatus['respondeu_2'] ?? 0);
        $stats['aguardando_2']      = $byStatus['aguardando_2']       ?? 0;
        $stats['lote_2_enviado']    = $byStatus['lote_enviado_2']    ?? 0;
        $stats['sem_resposta']      = $byStatus['sem_resposta']      ?? 0;
        $stats['standby']           = $byStatus['standby']           ?? 0;
        $stats['numero_invalido']   = $byStatus['numero_invalido']   ?? 0;

        // Lote ativo
        $loteAtivo = $pdo->prepare("
            SELECT id, status, total_clientes, enviados, erros, criado_em
            FROM reativacao_lotes
            WHERE company_id = ? AND status IN ('aguardando','em_andamento')
            ORDER BY criado_em DESC LIMIT 1
        ");
        $loteAtivo->execute([$companyId]);
        $stats['lote_ativo'] = $loteAtivo->fetch(PDO::FETCH_ASSOC) ?: null;

        // Cooldown: último lote concluído + 24h
        $availability = reactivation_availability($pdo, $companyId);
        $stats['pode_enviar']         = $availability['can_send'];
        $stats['pode_enviar_em']      = $availability['next_at_local'];
        $stats['cooldown_reason']     = $availability['reason'];
        $stats['daily_lotes_used']    = $availability['daily_lotes_used'];
        $stats['daily_contacts_used'] = $availability['daily_contacts_used'];
        $stats['remaining_lotes']     = $availability['remaining_lotes'];
        $stats['remaining_contacts']  = $availability['remaining_contacts'];
        if (false) {
        $ultimo = $pdo->prepare("
            SELECT concluido_em FROM reativacao_lotes
            WHERE company_id = ? AND status = 'concluido'
            ORDER BY concluido_em DESC LIMIT 1
        ");
        $ultimo->execute([$companyId]);
        $ultimoConcluido = $ultimo->fetchColumn();
        $stats['pode_enviar_em'] = null;
        if ($ultimoConcluido) {
            $proximoPermitido = strtotime($ultimoConcluido) + 86400;
            if ($proximoPermitido > time()) {
                $stats['pode_enviar_em'] = date('Y-m-d H:i:s', $proximoPermitido);
            }
        }
        }

    } catch (Throwable $e) {
        $stats['error'] = $e->getMessage();
    }
    echo json_encode($stats);
    exit;
}

/* ═══════════════════════════════════════════════════
   GET ELIGIBLE
═══════════════════════════════════════════════════ */
if ($action === 'get_eligible') {
    $diasSemVisita = (int)($_GET['dias'] ?? 60);
    $limite        = min((int)($_GET['limite'] ?? 30), 50);
    $contexto      = $_GET['contexto'] ?? 'todos';
    $tentativa     = (int)($_GET['tentativa'] ?? 1);

    try {
        $where  = "c.company_id = ? AND c.whatsapp IS NOT NULL AND c.whatsapp != ''";
        $params = [$companyId];

        if ($tentativa === 1) {
            $where .= " AND (c.reativ_status IS NULL OR c.reativ_status = '' OR c.reativ_status = 'elegivel')";
        } elseif ($tentativa === 2) {
            $where .= " AND c.reativ_status = 'aguardando_2'";
        }

        // Dias sem visita/compra
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$diasSemVisita} days"));
        $where .= " AND (c.ultimo_atendimento_em IS NULL OR c.ultimo_atendimento_em < ?)";
        $params[] = $cutoff;

        // Filtro de contexto via tags
        if ($contexto === 'pdv') {
            $where .= " AND c.tags LIKE '%pdv%'";
        } elseif ($contexto === 'barbearia') {
            $where .= " AND c.tags LIKE '%barbearia%'";
        } elseif ($contexto === 'whatsapp') {
            $where .= " AND c.tags LIKE '%whatsapp%' AND c.tags NOT LIKE '%pdv%' AND c.tags NOT LIKE '%barbearia%'";
        }

        // Exclui contatos que já estão em lotes das últimas 24h
        $where .= " AND c.id NOT IN (
            SELECT DISTINCT re.client_id
            FROM reativacao_envios re
            INNER JOIN reativacao_lotes rl ON rl.id = re.lote_id
            WHERE rl.company_id = ?
              AND rl.criado_em > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND rl.status != 'cancelado'
        )";
        $params[] = $companyId;

        $stmt = $pdo->prepare("
            SELECT c.id, c.nome, c.whatsapp, c.tags, c.ultimo_atendimento_em,
                   c.reativ_status, c.reativ_tentativas,
                   0 as has_appt
            FROM clients c
            WHERE {$where}
            ORDER BY RAND()
            LIMIT {$limite}
        ");
        $stmt->execute($params);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Adiciona contexto detectado e preview da mensagem
        $msgs = get_messages($pdo, $companyId);
        foreach ($clients as &$cl) {
            $ctx      = detect_context($cl);
            $cl['contexto_detectado'] = $ctx;
            $cl['msg_preview'] = build_message($ctx, $tentativa, $cl['nome'], 0, $pdo, $companyId);
            // dias sem visita
            if ($cl['ultimo_atendimento_em']) {
                $cl['dias_ausente'] = (int)(new DateTime('today'))->diff(new DateTime($cl['ultimo_atendimento_em']))->days;
            } else {
                $cl['dias_ausente'] = 999;
            }
        }
        unset($cl);

        // Conta quantos ainda disponíveis (para info ao usuário)
        $countAvail = $pdo->prepare("
            SELECT COUNT(*) FROM clients c
            WHERE c.company_id = ?
              AND c.whatsapp IS NOT NULL AND c.whatsapp != ''
              AND (c.reativ_status IS NULL OR c.reativ_status = '' OR c.reativ_status = 'elegivel')
              AND c.id NOT IN (
                  SELECT DISTINCT re.client_id
                  FROM reativacao_envios re
                  INNER JOIN reativacao_lotes rl ON rl.id = re.lote_id
                  WHERE rl.company_id = ?
                    AND rl.criado_em > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND rl.status != 'cancelado'
              )
        ");
        $countAvail->execute([$companyId, $companyId]);
        $totalDisponivel = (int)$countAvail->fetchColumn();

        echo json_encode([
            'ok'               => true,
            'clients'          => $clients,
            'total'            => count($clients),
            'total_disponivel' => $totalDisponivel,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   CREATE LOTE
═══════════════════════════════════════════════════ */
// ── GET: clientes recentes da barbearia ──────────────────────
if ($action === 'get_pos_barbearia') {
    $limite  = max(5, min(100, (int)($_GET['limite'] ?? 20)));
    $dataIni = $_GET['data_ini'] ?? date('Y-m-d', strtotime('-7 days'));
    $dataFim = $_GET['data_fim'] ?? date('Y-m-d');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni)) $dataIni = date('Y-m-d', strtotime('-7 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim))  $dataFim = date('Y-m-d');
    if ($dataIni > $dataFim) [$dataIni, $dataFim] = [$dataFim, $dataIni];
    if ($dataIni > $dataFim) $dataIni = $dataFim; // segurança

    try {
        $apptCols    = $pdo->query('SHOW COLUMNS FROM appointments')->fetchAll(PDO::FETCH_COLUMN);
        $hasClientId = in_array('client_id', $apptCols);

        $clients = [];

        /* ── Estratégia 1: appointments com client_id vinculado ── */
        if ($hasClientId) {
            $s = $pdo->prepare("
                SELECT
                    c.id,
                    c.nome,
                    c.whatsapp,
                    SUBSTRING_INDEX(TRIM(c.nome),' ',1)    AS primeiro_nome,
                    MAX(a.date)                             AS ultimo_agendamento,
                    DATE_FORMAT(MAX(a.date),'%d/%m/%Y')    AS ultimo_agendamento_fmt,
                    DATEDIFF(CURDATE(), MAX(a.date))        AS dias_desde_agendamento
                FROM clients c
                INNER JOIN appointments a
                    ON  a.client_id  = c.id
                    AND a.company_id = c.company_id
                WHERE c.company_id  = ?
                  AND c.whatsapp IS NOT NULL AND c.whatsapp != ''
                  AND a.date BETWEEN ? AND ?
                GROUP BY c.id, c.nome, c.whatsapp
                ORDER BY MAX(a.date) DESC
                LIMIT 300
            ");
            $s->execute([$companyId, $dataIni, $dataFim]);
            $clients = $s->fetchAll(PDO::FETCH_ASSOC);
        }

        /* ── Estratégia 2: busca pelo telefone do agendamento ──
           Funciona mesmo sem client_id, cruzando pelo número de telefone
        ── */
        if (empty($clients)) {
            // Descobre colunas disponíveis em appointments
            $aColsAll = $pdo->query('SHOW COLUMNS FROM appointments')->fetchAll(PDO::FETCH_COLUMN);
            $phoneCol = in_array('phone', $aColsAll) ? 'a.phone'
                      : (in_array('customer_phone', $aColsAll) ? 'a.customer_phone' : null);

            if ($phoneCol) {
                $s2 = $pdo->prepare("
                    SELECT
                        c.id,
                        c.nome,
                        c.whatsapp,
                        SUBSTRING_INDEX(TRIM(c.nome),' ',1) AS primeiro_nome,
                        MAX(a.date)                          AS ultimo_agendamento,
                        DATE_FORMAT(MAX(a.date),'%d/%m/%Y') AS ultimo_agendamento_fmt,
                        DATEDIFF(CURDATE(), MAX(a.date))     AS dias_desde_agendamento
                    FROM appointments a
                    INNER JOIN clients c
                        ON  c.company_id = a.company_id
                        AND (
                            REGEXP_REPLACE(c.whatsapp,'[^0-9]','') LIKE CONCAT('%', RIGHT(REGEXP_REPLACE({$phoneCol},'[^0-9]',''),8))
                            OR
                            REGEXP_REPLACE({$phoneCol},'[^0-9]','') LIKE CONCAT('%', RIGHT(REGEXP_REPLACE(c.whatsapp,'[^0-9]',''),8))
                        )
                    WHERE a.company_id = ?
                      AND c.whatsapp IS NOT NULL AND c.whatsapp != ''
                      AND a.date BETWEEN ? AND ?
                    GROUP BY c.id, c.nome, c.whatsapp
                    ORDER BY MAX(a.date) DESC
                    LIMIT 300
                ");
                $s2->execute([$companyId, $dataIni, $dataFim]);
                $clients = $s2->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Fallback: busca por tag barbearia quando não tem client_id ou retornou vazio
        if (empty($clients)) {
            $s3 = $pdo->prepare("
                SELECT
                    c.id,
                    c.nome,
                    c.whatsapp,
                    SUBSTRING_INDEX(TRIM(c.nome),' ',1) AS primeiro_nome,
                    DATE(c.updated_at)                  AS ultimo_agendamento,
                    DATE_FORMAT(c.updated_at,'%d/%m/%Y') AS ultimo_agendamento_fmt,
                    DATEDIFF(CURDATE(), DATE(c.updated_at)) AS dias_desde_agendamento
                FROM clients c
                WHERE c.company_id = ?
                  AND c.whatsapp IS NOT NULL AND c.whatsapp != ''
                  AND IFNULL(c.tags,'') LIKE '%barbearia%'
                  AND DATE(c.updated_at) BETWEEN ? AND ?
                ORDER BY c.updated_at DESC
                LIMIT 300
            ");
            $s3->execute([$companyId, $dataIni, $dataFim]);
            $clients = $s3->fetchAll(PDO::FETCH_ASSOC);
        }

        /* ── Estratégia 4 (último recurso): busca direta na tabela
           appointments pelo nome do cliente, sem JOIN ── */
        if (empty($clients)) {
            $aColsAll = $pdo->query('SHOW COLUMNS FROM appointments')->fetchAll(PDO::FETCH_COLUMN);
            $nameCol  = in_array('customer_name', $aColsAll) ? 'customer_name'
                      : (in_array('nome', $aColsAll) ? 'nome' : null);
            $phoneCol = in_array('phone', $aColsAll) ? 'phone'
                      : (in_array('customer_phone', $aColsAll) ? 'customer_phone' : null);

            if ($nameCol && $phoneCol) {
                $s4 = $pdo->prepare("
                    SELECT
                        MIN(0)                              AS id,
                        {$nameCol}                          AS nome,
                        {$phoneCol}                         AS whatsapp,
                        SUBSTRING_INDEX(TRIM({$nameCol}),' ',1) AS primeiro_nome,
                        MAX(date)                           AS ultimo_agendamento,
                        DATE_FORMAT(MAX(date),'%d/%m/%Y')  AS ultimo_agendamento_fmt,
                        DATEDIFF(CURDATE(), MAX(date))      AS dias_desde_agendamento
                    FROM appointments
                    WHERE company_id = ?
                      AND {$phoneCol} IS NOT NULL AND {$phoneCol} != ''
                      AND date BETWEEN ? AND ?
                    GROUP BY {$phoneCol}, {$nameCol}
                    ORDER BY MAX(date) DESC
                    LIMIT 300
                ");
                $s4->execute([$companyId, $dataIni, $dataFim]);
                $rawAppts = $s4->fetchAll(PDO::FETCH_ASSOC);

                // Tenta cruzar com clients pelo telefone
                foreach ($rawAppts as $ra) {
                    $waClean = preg_replace('/\D/', '', $ra['whatsapp'] ?? '');
                    if (strlen($waClean) < 8) continue;
                    $last8 = substr($waClean, -8);
                    $cFind = $pdo->prepare("
                        SELECT id, nome, whatsapp
                        FROM clients
                        WHERE company_id = ?
                          AND REGEXP_REPLACE(whatsapp,'[^0-9]','') LIKE ?
                        LIMIT 1
                    ");
                    $cFind->execute([$companyId, '%' . $last8]);
                    $found = $cFind->fetch(PDO::FETCH_ASSOC);

                    if ($found) {
                        $clients[] = array_merge($ra, [
                            'id'      => $found['id'],
                            'nome'    => $found['nome'],
                            'whatsapp'=> $found['whatsapp'],
                        ]);
                    } else {
                        // Não tem cadastro ainda — usa dados do agendamento
                        $clients[] = array_merge($ra, ['id' => 0]);
                    }
                }
            }
        }

        // ── 1. Deduplicação por WhatsApp ─────────────────────────
        $seenWa = [];
        $unique = [];
        foreach ($clients as $c) {
            // Normaliza: so digitos, garante DDI 55
            $wa = preg_replace('/\D/', '', $c['whatsapp'] ?? '');
            if (strlen($wa) < 8) continue;
            if (strlen($wa) <= 11 && !str_starts_with($wa, '55')) $wa = '55' . $wa;
            // Ja vimos esse numero? Pula.
            if (isset($seenWa[$wa])) continue;
            $seenWa[$wa] = true;
            $unique[] = $c;
        }
        $clients = $unique;

        // ── 2. Remove clientes em cooldown (receberam msg < 7 dias) ──
        $allIds      = array_filter(array_map('intval', array_column($clients, 'id')));
        $cooldownIds = [];

        if (!empty($allIds)) {
            $ph = implode(',', array_fill(0, count($allIds), '?'));
            $cStmt = $pdo->prepare("
                SELECT client_id FROM pos_barbearia_cooldown
                WHERE company_id = ?
                  AND client_id IN ($ph)
                  AND expira_em > NOW()
            ");
            $cStmt->execute(array_merge([$companyId], array_values($allIds)));
            $cooldownIds = array_flip($cStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        // ── 3. Remove clientes que já re-agendaram após o último lote ──

        // ── 4. Aplica filtros e limita resultado final ────────────
        $final     = [];
        $removidos = ['cooldown' => 0, 'sem_cadastro' => 0];

        foreach ($clients as $c) {
            $cid = (int)$c['id'];
            if ($cid > 0 && isset($cooldownIds[$cid])) {
                $removidos['cooldown']++;
                continue;
            }
            $c['ultimo_servico'] = '';
            $final[] = $c;
            if (count($final) >= $limite) break;
        }

        echo json_encode([
            'ok'        => true,
            'clients'   => $final,
            'total'     => count($final),
            'periodo'   => ['ini' => $dataIni, 'fim' => $dataFim],
            'removidos' => $removidos,
        ]);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── POST: criar lote pós-barbearia ───────────────────────────
if ($action === 'create_lote_pos_barbearia') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $envios  = $body['envios']      ?? [];
    $obs     = $body['observacoes'] ?? 'Pós-barbearia';
    $varidx  = (int)($body['variacao_idx'] ?? 0);

    if (empty($envios)) {
        echo json_encode(['ok' => false, 'error' => 'Nenhum cliente selecionado.']);
        exit;
    }

    try {
        $now = gmdate('Y-m-d H:i:s');

        try {
            $pdo->exec("ALTER TABLE pos_barbearia_cooldown
                        ADD UNIQUE KEY uq_company_client (company_id, client_id)");
        } catch (Throwable $ignored) {}

        // Cria o lote
        $pdo->prepare("INSERT INTO reativacao_lotes
            (company_id, criado_em, status, contexto, total_clientes, enviados, erros, mensagem_idx, observacoes)
            VALUES (?, ?, 'aguardando', 'barbearia', ?, 0, 0, ?, ?)")
            ->execute([$companyId, $now, count($envios), $varidx,
                       ($obs ?: 'Pós-barbearia — variação '.($varidx+1))]);

        $loteId = (int)$pdo->lastInsertId();

        // Insere envios individuais
        $stmt = $pdo->prepare("INSERT INTO reativacao_envios
            (lote_id, client_id, company_id, whatsapp, nome, contexto, mensagem, tentativa, status)
            VALUES (?, ?, ?, ?, ?, 'barbearia', ?, 1, 'pendente')");

        foreach ($envios as $e) {
            $stmt->execute([
                $loteId,
                (int)$e['client_id'],
                $companyId,
                $e['whatsapp'],
                $e['nome'],
                $e['mensagem'],
            ]);
        }

        // Registra cooldown 7 dias (ON DUPLICATE KEY atualiza se ja existir)
        $coolStmt = $pdo->prepare("
            INSERT INTO pos_barbearia_cooldown 
                (company_id, client_id, whatsapp, lote_id, enviado_em, expira_em)
            VALUES 
                (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
            ON DUPLICATE KEY UPDATE
                lote_id    = VALUES(lote_id),
                enviado_em = NOW(),
                expira_em  = DATE_ADD(NOW(), INTERVAL 7 DAY)
        ");
        foreach ($envios as $e) {
            try {
                $coolStmt->execute([
                    $companyId,
                    (int)$e['client_id'],
                    $e['whatsapp'],
                    $loteId
                ]);
            } catch (Throwable $ignored) {}
        }

        echo json_encode(['ok' => true, 'lote_id' => $loteId, 'total' => count($envios)]);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'create_lote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $variations = $body['variations'] ?? []; // array de textos (1-3)
    if (!is_array($variations)) $variations = [];

    // Filtra variações vazias
    $variations = array_values(array_filter(array_map('trim', $variations)));
    $numVar     = count($variations);

    $clientIds = array_values(array_unique(array_filter(array_map('intval', $body['client_ids'] ?? []))));
    $tentativa = (int)($body['tentativa'] ?? 1);
    $observ    = trim($body['observacoes'] ?? '');
    $contexto  = trim($body['contexto']   ?? 'misto');

    if (empty($clientIds)) {
        echo json_encode(['ok' => false, 'error' => 'Nenhum cliente selecionado.']);
        exit;
    }

    // Verifica se já existe lote ativo
    $existing = $pdo->prepare("SELECT id FROM reativacao_lotes WHERE company_id=? AND status IN ('aguardando','em_andamento') LIMIT 1");
    $existing->execute([$companyId]);
    if ($existing->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'Já existe um lote ativo. Conclua ou cancele-o antes de criar um novo.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $availability = reactivation_availability($pdo, $companyId, count($clientIds));
        if (!$availability['can_send']) {
            $msg = 'Novo lote bloqueado temporariamente.';
            if ($availability['reason'] === 'intervalo_entre_lotes') {
                $msg = 'Aguarde pelo menos 1 hora entre lotes. Proximo lote liberado em ' . $availability['next_at_br'] . '.';
            } elseif ($availability['reason'] === 'limite_lotes_dia') {
                $msg = 'Limite diario de 3 lotes atingido. Proximo lote liberado em ' . $availability['next_at_br'] . '.';
            } elseif ($availability['reason'] === 'limite_contatos_dia') {
                $msg = 'Limite diario de 90 contatos atingido. Restam ' . $availability['remaining_contacts'] . ' contatos hoje. Proximo lote liberado em ' . $availability['next_at_br'] . '.';
            }
            throw new RuntimeException($msg);
        }

        // Cria o lote
        $pdo->prepare("
            INSERT INTO reativacao_lotes (company_id, criado_em, status, contexto, total_clientes, criado_por, observacoes)
            VALUES (?, NOW(), 'aguardando', ?, ?, ?, ?)
        ")->execute([$companyId, $contexto, count($clientIds), $userId, $observ ?: null]);
        $loteId = (int)$pdo->lastInsertId();

        // Busca dados dos clientes selecionados
        $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, nome, whatsapp, tags,
                   0 as has_appt
            FROM clients
            WHERE id IN ($placeholders) AND company_id = ?
        ");
        $stmt->execute([...$clientIds, $companyId]);
        $clientsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($clientsData)) {
            throw new RuntimeException('Nenhum cliente valido encontrado para este lote.');
        }

        $realTotal = count($clientsData);
        $pdo->prepare("UPDATE reativacao_lotes SET total_clientes=? WHERE id=?")->execute([$realTotal, $loteId]);

        // Insere os envios individuais com mensagens já definidas
        $total = count($clientsData);
        foreach ($clientsData as $idx => $cl) {
            $ctx = detect_context($cl);

            if ($numVar > 0) {
                // Distribui homogeneamente: cada variação recebe floor(total/numVar) + 1 se sobrar
                $varIdx = $idx % $numVar;
                $firstName = explode(' ', trim($cl['nome']))[0];
                $msg = str_replace('{nome}', $firstName, $variations[$varIdx]);
            } else {
                $msg = build_message($ctx, $tentativa, $cl['nome'], $idx % 3, $pdo, $companyId);
            }

            $pdo->prepare("
                INSERT INTO reativacao_envios
                    (lote_id, client_id, company_id, whatsapp, nome, contexto, mensagem, tentativa, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendente')
            ")->execute([$loteId, $cl['id'], $companyId, $cl['whatsapp'], $cl['nome'], $ctx, $msg, $tentativa]);
        }

        $pdo->commit();
        echo json_encode([
            'ok'              => true,
            'lote_id'         => $loteId,
            'total'           => $realTotal,
            'variations_used' => $numVar,
        ]);

    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   GET LOTE (detalhes + progresso)
═══════════════════════════════════════════════════ */
if ($action === 'get_lote') {
    $loteId = (int)($_GET['id'] ?? 0);
    try {
        $lote = $pdo->prepare("SELECT * FROM reativacao_lotes WHERE id=? AND company_id=?");
        $lote->execute([$loteId, $companyId]);
        $loteData = $lote->fetch(PDO::FETCH_ASSOC);
        if (!$loteData) { echo json_encode(['ok'=>false,'error'=>'Lote não encontrado']); exit; }

        $envios = $pdo->prepare("
            SELECT re.id, re.nome, re.whatsapp, re.contexto, re.tentativa,
                   re.status, re.enviado_em, re.respondeu_em, re.erro_msg,
                   LEFT(re.mensagem, 80) as msg_preview
            FROM reativacao_envios re
            WHERE re.lote_id = ?
            ORDER BY re.id ASC
        ");
        $envios->execute([$loteId]);

        echo json_encode([
            'ok'     => true,
            'lote'   => $loteData,
            'envios' => $envios->fetchAll(PDO::FETCH_ASSOC),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   GET LOTES (histórico)
═══════════════════════════════════════════════════ */
if ($action === 'get_lotes') {
    try {
        $stmt = $pdo->prepare("
            SELECT l.*,
                   (SELECT COUNT(*) FROM reativacao_envios WHERE lote_id=l.id AND status='respondeu') as responderam
            FROM reativacao_lotes l
            WHERE l.company_id = ?
            ORDER BY l.criado_em DESC
            LIMIT 50
        ");
        $stmt->execute([$companyId]);
        echo json_encode(['ok' => true, 'lotes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   SEND NEXT — envia UMA mensagem pendente do lote ativo
   O frontend chama isso em loop com delay randomizado
═══════════════════════════════════════════════════ */
if ($action === 'send_next' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $loteId = (int)($_POST['lote_id'] ?? 0);

    // Valida horário permitido (09:00 - 20:00)
    $hora = (int)date('H');
    if ($hora < 9 || $hora >= 20) {
        echo json_encode(['ok' => false, 'error' => 'Fora do horário permitido (09:00–20:00)', 'paused' => true]);
        exit;
    }

    // Garante colunas críticas antes de qualquer query
    try {
        $eCols = $pdo->query('SHOW COLUMNS FROM reativacao_envios')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tentativa',    $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN tentativa    TINYINT DEFAULT 1 AFTER mensagem");
        if (!in_array('respondeu_em', $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN respondeu_em DATETIME NULL");
        if (!in_array('erro_msg',     $eCols)) $pdo->exec("ALTER TABLE reativacao_envios ADD COLUMN erro_msg     TEXT NULL");
    } catch (Throwable $_mc) {}

    try {
        // Pega próximo pendente
        $stmt = $pdo->prepare("
            SELECT * FROM reativacao_envios
            WHERE lote_id = ? AND status = 'pendente'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $pdo->beginTransaction();
        $stmt->execute([$loteId]);
        $envio = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$envio) {
            // Lote concluído
            $pdo->prepare("UPDATE reativacao_lotes SET status='concluido', concluido_em=NOW() WHERE id=?")->execute([$loteId]);
            $pdo->commit();
            echo json_encode(['ok' => true, 'done' => true, 'msg' => 'Lote concluído!']);
            exit;
        }

        // Marca como lote em andamento
        $pdo->prepare("UPDATE reativacao_lotes SET status='em_andamento', iniciado_em=COALESCE(iniciado_em,NOW()) WHERE id=?")->execute([$loteId]);

        // Tenta enviar
        $result = send_whatsapp($envio['whatsapp'], $envio['mensagem'], $pdo, $companyId);

        if ($result['ok']) {
            // Sucesso
            $pdo->prepare("UPDATE reativacao_envios SET status='enviado', enviado_em=NOW() WHERE id=?")->execute([$envio['id']]);
            $pdo->prepare("UPDATE reativacao_lotes SET enviados=enviados+1 WHERE id=?")->execute([$loteId]);

            // Atualiza status do cliente
            $tentativaEnvio = (int)($envio['tentativa'] ?? 1);
            $novoStatus = $tentativaEnvio === 1 ? 'lote_enviado_1' : 'lote_enviado_2';
            $pdo->prepare("
                UPDATE clients
                   SET reativ_status      = ?,
                       reativ_ultimo_envio = NOW(),
                       reativ_tentativas   = ?
                WHERE id = ? AND company_id = ?
            ")->execute([$novoStatus, $tentativaEnvio, $envio['client_id'], $companyId]);

        } else {
            // Erro de envio
            $pdo->prepare("UPDATE reativacao_envios SET status='erro', erro_msg=? WHERE id=?")->execute([$result['error'], $envio['id']]);
            $pdo->prepare("UPDATE reativacao_lotes SET erros=erros+1 WHERE id=?")->execute([$loteId]);
        }

        $pdo->commit();

        // Conta quantos pendentes ainda restam
        $stmtRestantes = $pdo->prepare("SELECT COUNT(*) FROM reativacao_envios WHERE lote_id=? AND status='pendente'");
        $stmtRestantes->execute([$loteId]);
        $restantes = (int)$stmtRestantes->fetchColumn();

        echo json_encode([
            'ok'        => true,
            'done'      => false,
            'enviado'   => $result['ok'],
            'nome'      => $envio['nome'],
            'whatsapp'  => substr($envio['whatsapp'], 0, 4) . '****' . substr($envio['whatsapp'], -4),
            'msg_preview' => mb_substr($envio['mensagem'], 0, 60) . '...',
            'restantes' => (int)$restantes,
            'erro'      => $result['ok'] ? null : $result['error'],
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'fatal' => true]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   CANCEL LOTE
═══════════════════════════════════════════════════ */
if ($action === 'cancel_lote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $loteId = (int)($_POST['lote_id'] ?? 0);
    try {
        $pdo->prepare("UPDATE reativacao_lotes SET status='cancelado' WHERE id=? AND company_id=?")->execute([$loteId, $companyId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   GET CLIENTS BY STATUS
═══════════════════════════════════════════════════ */
if ($action === 'get_clients_by_status') {
    $status = $_GET['status'] ?? 'sem_resposta';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $pp     = 25;
    $offset = ($page - 1) * $pp;
    $allowedStatuses = ['elegivel','standby','numero_invalido','aguardando_2','sem_resposta','lote_enviado_1','lote_enviado_2','respondeu_1','respondeu_2'];

    if (!in_array($status, $allowedStatuses, true)) {
        echo json_encode(['ok'=>false,'error'=>'Status invalido.']);
        exit;
    }

    try {
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE company_id=? AND reativ_status=?");
        $stmtTotal->execute([$companyId, $status]);
        $total = (int)$stmtTotal->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, nome, whatsapp, tags, reativ_status, reativ_tentativas, reativ_ultimo_envio, ultimo_atendimento_em
            FROM clients
            WHERE company_id=? AND reativ_status=?
            ORDER BY reativ_ultimo_envio DESC
            LIMIT {$pp} OFFSET {$offset}
        ");
        $stmt->execute([$companyId, $status]);
        echo json_encode(['ok'=>true,'clients'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'total'=>(int)$total,'page'=>$page]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   UPDATE CLIENT STATUS (manual)
═══════════════════════════════════════════════════ */
if ($action === 'update_client_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $clientIds = array_map('intval', (array)($body['client_ids'] ?? []));
    $novoStatus= trim($body['status'] ?? '');
    $allowed   = ['elegivel','standby','numero_invalido','aguardando_2','sem_resposta'];

    if (!in_array($novoStatus, $allowed) || empty($clientIds)) {
        echo json_encode(['ok'=>false,'error'=>'Status ou IDs inválidos.']); exit;
    }
    try {
        $pl = implode(',', array_fill(0, count($clientIds), '?'));
        $pdo->prepare("UPDATE clients SET reativ_status=? WHERE id IN ($pl) AND company_id=?")
            ->execute([$novoStatus, ...$clientIds, $companyId]);
        echo json_encode(['ok'=>true,'updated'=>count($clientIds)]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   MARK RESPONDED — chamado pelo webhook quando cliente responde
═══════════════════════════════════════════════════ */
if ($action === 'mark_responded') {
    $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    if (!$whatsapp) { echo json_encode(['ok'=>false]); exit; }

    // tenta com e sem DDI
    $wa55 = str_starts_with($whatsapp,'55') ? $whatsapp : '55'.$whatsapp;
    $waLc = str_starts_with($whatsapp,'55') ? substr($whatsapp,2) : $whatsapp;

    try {
        // Atualiza último envio pendente para 'respondeu'
        $pdo->prepare("
            UPDATE reativacao_envios re
            JOIN clients c ON c.id = re.client_id
            SET re.status = 'respondeu', re.respondeu_em = NOW()
            WHERE (c.whatsapp = ? OR c.whatsapp = ?)
              AND c.company_id = ?
              AND re.status = 'enviado'
              AND re.id = (
                SELECT MAX(re2.id) FROM reativacao_envios re2
                JOIN clients c2 ON c2.id = re2.client_id
                WHERE (c2.whatsapp = ? OR c2.whatsapp = ?) AND c2.company_id = ? AND re2.status='enviado'
              )
        ")->execute([$wa55,$waLc,$companyId,$wa55,$waLc,$companyId]);

        // Atualiza status do cliente
        $stmt = $pdo->prepare("
            SELECT id, reativ_tentativas FROM clients
            WHERE (whatsapp = ? OR whatsapp = ?) AND company_id = ? LIMIT 1
        ");
        $stmt->execute([$wa55,$waLc,$companyId]);
        $cl = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cl) {
            $novoStatus = (int)$cl['reativ_tentativas'] <= 1 ? 'respondeu_1' : 'respondeu_2';
            $pdo->prepare("UPDATE clients SET reativ_status=? WHERE id=?")->execute([$novoStatus,$cl['id']]);
        }

        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}


/* ═══════════════════════════════════════════════════
   GET MESSAGES CONFIG
═══════════════════════════════════════════════════ */
if ($action === 'get_messages_config') {
    // Garante tabela existe
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reativacao_mensagens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            contexto VARCHAR(20) NOT NULL, tentativa TINYINT NOT NULL,
            variacao_idx TINYINT NOT NULL, mensagem TEXT NOT NULL,
            validade VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_msg (company_id, contexto, tentativa, variacao_idx),
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    $defaults = get_default_messages();
    $custom   = [];
    try {
        $stmt = $pdo->prepare("SELECT contexto, tentativa, variacao_idx, mensagem FROM reativacao_mensagens WHERE company_id=?");
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $custom[$r['contexto']][(int)$r['tentativa']][(int)$r['variacao_idx']] = $r['mensagem'];
        }
    } catch (Throwable $e) {}

    // Mescla: customizado por cima do default
    $result = [];
    foreach ($defaults as $ctx => $tents) {
        foreach ($tents as $tent => $vars) {
            foreach ($vars as $idx => $msg) {
                $result[] = [
                    'contexto'     => $ctx,
                    'tentativa'    => (int)$tent,
                    'variacao_idx' => (int)$idx,
                    'mensagem'     => $custom[$ctx][$tent][$idx] ?? $msg,
                    'is_custom'    => isset($custom[$ctx][$tent][$idx]),
                    'default'      => $msg,
                ];
            }
        }
    }
    echo json_encode(['ok' => true, 'mensagens' => $result]);
    exit;
}

/* ═══════════════════════════════════════════════════
   SAVE MESSAGES CONFIG
═══════════════════════════════════════════════════ */
if ($action === 'save_messages_config') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $mensagens = $body['mensagens'] ?? [];
    $defaults  = get_default_messages();

    try {
        // Garante tabela
        $pdo->exec("CREATE TABLE IF NOT EXISTS reativacao_mensagens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            contexto VARCHAR(20) NOT NULL, tentativa TINYINT NOT NULL,
            variacao_idx TINYINT NOT NULL, mensagem TEXT NOT NULL,
            validade VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_msg (company_id, contexto, tentativa, variacao_idx),
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $saved = 0;
        $reset = 0;
        foreach ($mensagens as $m) {
            $ctx  = trim($m['contexto'] ?? '');
            $tent = (int)($m['tentativa'] ?? 0);
            $idx  = (int)($m['variacao_idx'] ?? 0);
            $msg  = trim($m['mensagem'] ?? '');
            if (!$ctx || !$tent || !$msg) continue;

            $default = $defaults[$ctx][$tent][$idx] ?? null;

            if ($msg === $default) {
                // Voltou ao padrão: remove do banco
                $pdo->prepare("DELETE FROM reativacao_mensagens WHERE company_id=? AND contexto=? AND tentativa=? AND variacao_idx=?")
                    ->execute([$companyId, $ctx, $tent, $idx]);
                $reset++;
            } else {
                $pdo->prepare("INSERT INTO reativacao_mensagens (company_id, contexto, tentativa, variacao_idx, mensagem, updated_at)
                    VALUES (?,?,?,?,?,NOW())
                    ON DUPLICATE KEY UPDATE mensagem=VALUES(mensagem), updated_at=NOW()")
                    ->execute([$companyId, $ctx, $tent, $idx, $msg]);
                $saved++;
            }
        }
        echo json_encode(['ok' => true, 'saved' => $saved, 'reset' => $reset]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   SEND TEST MESSAGE
═══════════════════════════════════════════════════ */
if ($action === 'send_test') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $whatsapp = preg_replace('/\D/', '', $body['whatsapp'] ?? '');
    $mensagem = trim($body['mensagem'] ?? '');

    if (!$whatsapp || !$mensagem) {
        echo json_encode(['ok' => false, 'error' => 'WhatsApp e mensagem são obrigatórios.']);
        exit;
    }
    if (!str_starts_with($whatsapp, '55')) $whatsapp = '55' . $whatsapp;

    $result = send_whatsapp($whatsapp, $mensagem, $pdo, $companyId);
    echo json_encode($result);
    exit;
}


/* ═══════════════════════════════════════════════════
   GET / SAVE EVOLUTION CONFIG
═══════════════════════════════════════════════════ */
if ($action === 'get_evolution_config') {
    // Garante colunas na tabela companies
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('evolution_api_url',  $cols)) $pdo->exec("ALTER TABLE companies ADD COLUMN evolution_api_url  VARCHAR(255) NULL");
        if (!in_array('evolution_api_key',  $cols)) $pdo->exec("ALTER TABLE companies ADD COLUMN evolution_api_key  VARCHAR(255) NULL");
        if (!in_array('evolution_instance', $cols)) $pdo->exec("ALTER TABLE companies ADD COLUMN evolution_instance VARCHAR(100) NULL");
    } catch (Throwable $e) {}

    try {
        $row = $pdo->prepare("SELECT evolution_api_url, evolution_api_key, evolution_instance FROM companies WHERE id=? LIMIT 1");
        $row->execute([$companyId]);
        $cfg = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true,
            'api_url'  => $cfg['evolution_api_url']  ?? '',
            'api_key'  => $cfg['evolution_api_key']  ?? '',
            'instance' => $cfg['evolution_instance'] ?? '',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_evolution_config') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $api_url  = trim($body['api_url']  ?? '');
    $api_key  = trim($body['api_key']  ?? '');
    $instance = trim($body['instance'] ?? '');

    try {
        // Garante colunas
        $cols = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('evolution_api_url',  $cols)) $pdo->exec("ALTER TABLE companies ADD COLUMN evolution_api_url  VARCHAR(255) NULL");
        if (!in_array('evolution_api_key',  $cols)) $pdo->exec("ALTER TABLE companies ADD COLUMN evolution_api_key  VARCHAR(255) NULL");
        if (!in_array('evolution_instance', $cols)) $pdo->exec("ALTER TABLE companies ADD COLUMN evolution_instance VARCHAR(100) NULL");

        $pdo->prepare("UPDATE companies SET evolution_api_url=?, evolution_api_key=?, evolution_instance=? WHERE id=?")
            ->execute([$api_url ?: null, $api_key ?: null, $instance ?: null, $companyId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   TEST EVOLUTION CONNECTION
═══════════════════════════════════════════════════ */
if ($action === 'test_evolution_connection') {
    $cfg = get_evolution_config($pdo, $companyId);
    if (!$cfg['base'] || !$cfg['key'] || !$cfg['instance']) {
        echo json_encode(['ok' => false, 'error' => 'Preencha URL, API Key e Instância antes de testar.']);
        exit;
    }
    // Testa conexão com endpoint de info da instância
    $url = "{$cfg['base']}/instance/fetchInstances";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . $cfg['key']],
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) { echo json_encode(['ok' => false, 'error' => 'cURL: ' . $curlErr]); exit; }
    if ($code >= 200 && $code < 300) { echo json_encode(['ok' => true, 'message' => 'Conexão estabelecida com sucesso! ✅']); exit; }
    echo json_encode(['ok' => false, 'error' => "HTTP {$code} — verifique URL e API Key."]);
    exit;
}

/* ═══════════════════════════════════════════════════
   GET / SAVE PÓS-BARBEARIA MESSAGES CONFIG
═══════════════════════════════════════════════════ */
if ($action === 'get_pb_messages_config') {
    // Mensagens padrão do pós-barbearia
    $defaults = [
        ['titulo' => '📅 Lembrete de agenda',    'texto' => "Oi, {nome}! 👋\n\nJá faz alguns dias desde sua última visita na *Formen Barbearia*. ✂️\n\nSua agenda está aberta — escolha o melhor horário:\n👉 {link_agenda}\n\nTe esperamos!", 'validade' => ''],
        ['titulo' => '🛍️ Promoção / Lançamento', 'texto' => "Oi, {nome}! 😎\n\nTemos novidades esperando por você na *Formen Barbearia*! ✂️\n\nNovos produtos, novos serviços e promoções imperdíveis.\n\nAgende seu horário:\n👉 {link_agenda}\n\nAté breve!", 'validade' => ''],
        ['titulo' => '🔗 Link da agenda online', 'texto' => "Oi, {nome}! Tudo bem? ✂️\n\nQue tal marcar sua próxima visita na *Formen Barbearia*?\n\nAgende online em qualquer horário:\n👉 {link_agenda}\n\nÉ rápido e fácil! 😉", 'validade' => ''],
        ['titulo' => '⭐ Feedback + retorno',     'texto' => "Oi, {nome}! 🙏\n\nEsperamos que tenha gostado do seu atendimento na *Formen Barbearia*! ✂️\n\nSua opinião é muito importante pra gente. E quando quiser voltar, sua agenda está esperando:\n👉 {link_agenda}", 'validade' => ''],
        ['titulo' => '🎁 Oferta exclusiva',       'texto' => "Oi, {nome}! 🎉\n\nTemos uma oferta especial para clientes fiéis como você na *Formen Barbearia*! ✂️\n\nAgende agora e aproveite:\n👉 {link_agenda}\n\nValidade limitada!", 'validade' => ''],
    ];

    try {
        // Garante coluna validade
        $rmCols = $pdo->query('SHOW COLUMNS FROM reativacao_mensagens')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('validade', $rmCols)) {
            $pdo->exec("ALTER TABLE reativacao_mensagens ADD COLUMN validade VARCHAR(255) NULL AFTER mensagem");
        }

        $stmt = $pdo->prepare("
            SELECT variacao_idx, mensagem, validade
            FROM reativacao_mensagens
            WHERE company_id=? AND contexto='pos_barbearia' AND tentativa=1
            ORDER BY variacao_idx ASC
        ");
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $custom = [];
        foreach ($rows as $r) $custom[(int)$r['variacao_idx']] = $r;

        $result = [];
        foreach ($defaults as $i => $d) {
            $result[] = [
                'variacao_idx' => $i,
                'titulo' => $d['titulo'],
                'texto' => $custom[$i]['mensagem'] ?? $d['texto'],
                'validade' => $custom[$i]['validade'] ?? $d['validade'],
                'is_custom' => isset($custom[$i]),
                'default_texto' => $d['texto'],
            ];
        }
        echo json_encode(['ok' => true, 'mensagens' => $result]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_pb_messages_config') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $mensagens = $body['mensagens'] ?? [];

    try {
        $rmCols = $pdo->query('SHOW COLUMNS FROM reativacao_mensagens')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('validade', $rmCols)) {
            $pdo->exec("ALTER TABLE reativacao_mensagens ADD COLUMN validade VARCHAR(255) NULL AFTER mensagem");
        }

        $saved = 0;
        foreach ($mensagens as $m) {
            $idx = (int)($m['variacao_idx'] ?? 0);
            $texto = trim($m['texto'] ?? '');
            $validade = trim($m['validade'] ?? '');
            if (!$texto) continue;

            $pdo->prepare("
                INSERT INTO reativacao_mensagens
                    (company_id, contexto, tentativa, variacao_idx, mensagem, validade, updated_at)
                VALUES (?, 'pos_barbearia', 1, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    mensagem   = VALUES(mensagem),
                    validade   = VALUES(validade),
                    updated_at = NOW()
            ")->execute([$companyId, $idx, $texto, $validade ?: null]);
            $saved++;
        }
        echo json_encode(['ok' => true, 'saved' => $saved]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   PROMOÇÕES — GET CONTATOS DISPONÍVEIS HOJE
═══════════════════════════════════════════════════ */
if ($action === 'get_promo_contacts') {
    $limite = max(10, min(100, (int)($_GET['limite'] ?? 30)));
    $offset = max(0, (int)($_GET['offset'] ?? 0)); // paginação por lote

    try {
        // Busca todos com WhatsApp válido, exceto os que já receberam hoje
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.nome,
                c.whatsapp,
                SUBSTRING_INDEX(TRIM(c.nome),' ',1) AS primeiro_nome,
                IFNULL(c.tags,'') AS tags,
                c.ultimo_atendimento_em
            FROM clients c
            WHERE c.company_id = ?
              AND c.whatsapp IS NOT NULL
              AND c.whatsapp != ''
              AND LENGTH(REGEXP_REPLACE(c.whatsapp,'[^0-9]','')) >= 10
              AND NOT EXISTS (
                SELECT 1 FROM promocoes_cooldown pc
                WHERE pc.company_id = c.company_id
                  AND pc.whatsapp   = c.whatsapp
                  AND pc.enviado_em = CURDATE()
              )
            ORDER BY c.nome ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$companyId, $limite, $offset]);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total disponíveis hoje
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) FROM clients c
            WHERE c.company_id = ?
              AND c.whatsapp IS NOT NULL AND c.whatsapp != ''
              AND LENGTH(REGEXP_REPLACE(c.whatsapp,'[^0-9]','')) >= 10
              AND NOT EXISTS (
                SELECT 1 FROM promocoes_cooldown pc
                WHERE pc.company_id = c.company_id
                  AND pc.whatsapp   = c.whatsapp
                  AND pc.enviado_em = CURDATE()
              )
        ");
        $totalStmt->execute([$companyId]);
        $total = (int)$totalStmt->fetchColumn();

        // Total já enviados hoje
        $enviadosHojeStmt = $pdo->prepare("
            SELECT COUNT(*) FROM promocoes_cooldown
            WHERE company_id = ? AND enviado_em = CURDATE()
        ");
        $enviadosHojeStmt->execute([$companyId]);
        $enviadosHoje = (int)$enviadosHojeStmt->fetchColumn();

        // Deduplicação por WhatsApp normalizado
        $seen   = [];
        $unique = [];
        foreach ($clients as $c) {
            $wa = preg_replace('/\D/', '', $c['whatsapp']);
            if (strlen($wa) <= 11 && !str_starts_with($wa, '55')) $wa = '55' . $wa;
            if (isset($seen[$wa])) continue;
            $seen[$wa] = true;
            $unique[]  = $c;
        }

        echo json_encode([
            'ok'            => true,
            'clients'       => $unique,
            'total_disp'    => $total,
            'enviados_hoje' => $enviadosHoje,
            'offset_atual'  => $offset,
            'proximo_offset'=> $offset + $limite,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   PROMOÇÕES — GET / SAVE MENSAGENS
═══════════════════════════════════════════════════ */
if ($action === 'get_promo_mensagens') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, titulo, mensagem, validade, ativa
            FROM promocoes_mensagens
            WHERE company_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$companyId]);
        $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Se não tem nenhuma, retorna defaults
        if (empty($msgs)) {
            $msgs = [
                ['id'=>0,'titulo'=>'🏷️ Promoção Fim de Mês',    'mensagem'=>"Oi, {nome}! 🎉\n\nÉ fim de mês na *Formen Store* e preparamos uma promoção especial pra você!\n\n👕 Confira os lançamentos e aproveite as ofertas:\n👉 {link_loja}\n\nVálido por tempo limitado!", 'validade'=>'Fim de mês', 'ativa'=>1],
                ['id'=>0,'titulo'=>'✂️ Promoção Barbearia Seg/Ter','mensagem'=>"Oi, {nome}! ✂️\n\nSabia que às *Segundas e Terças* temos condições especiais na *Formen Barbearia*?\n\nAproveite e agende agora:\n👉 {link_agenda}\n\nVálido: Segunda e Terça-feira.", 'validade'=>'Seg. e Ter.', 'ativa'=>1],
                ['id'=>0,'titulo'=>'🎁 Voucher da Semana',        'mensagem'=>"Oi, {nome}! 😎\n\nTemos um voucher especial essa semana pra você na *Formen*!\n\nMostra essa mensagem na loja ou menciona ao agendar e garanta seu desconto exclusivo.\n\n✂️ Agenda: {link_agenda}\n👕 Loja: {link_loja}", 'validade'=>'Válido essa semana', 'ativa'=>1],
                ['id'=>0,'titulo'=>'📣 Novidade / Lançamento',    'mensagem'=>"Oi, {nome}! 👋\n\nA *Formen* tem novidades chegando essa semana!\n\nPasse na loja ou confira online:\n👉 {link_loja}\n\nSempre bom ter você por aqui! 😊", 'validade'=>'', 'ativa'=>1],
            ];
        }
        echo json_encode(['ok' => true, 'mensagens' => $msgs]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_promo_mensagem') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($body['id']       ?? 0);
    $titulo   = trim($body['titulo']    ?? '');
    $mensagem = trim($body['mensagem']  ?? '');
    $validade = trim($body['validade']  ?? '');
    $ativa    = isset($body['ativa']) ? (int)$body['ativa'] : 1;

    if (!$titulo || !$mensagem) {
        echo json_encode(['ok' => false, 'error' => 'Título e mensagem são obrigatórios.']);
        exit;
    }
    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE promocoes_mensagens SET titulo=?,mensagem=?,validade=?,ativa=?,updated_at=NOW() WHERE id=? AND company_id=?")
                ->execute([$titulo, $mensagem, $validade ?: null, $ativa, $id, $companyId]);
        } else {
            $pdo->prepare("INSERT INTO promocoes_mensagens (company_id,titulo,mensagem,validade,ativa) VALUES (?,?,?,?,?)")
                ->execute([$companyId, $titulo, $mensagem, $validade ?: null, $ativa]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_promo_mensagem') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM promocoes_mensagens WHERE id=? AND company_id=?")->execute([$id, $companyId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ═══════════════════════════════════════════════════
   PROMOÇÕES — CRIAR LOTE
═══════════════════════════════════════════════════ */
if ($action === 'create_lote_promo') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $envios    = $body['envios']      ?? [];
    $obs       = $body['observacoes'] ?? 'Promoção';
    $msgTitulo = $body['msg_titulo']  ?? 'Promoção';

    if (empty($envios)) {
        echo json_encode(['ok' => false, 'error' => 'Nenhum cliente selecionado.']);
        exit;
    }

    try {
        $now = gmdate('Y-m-d H:i:s');

        // Deduplicação por WhatsApp antes de inserir
        $seenWa  = [];
        $uniqEnv = [];
        foreach ($envios as $e) {
            $wa = preg_replace('/\D/', '', $e['whatsapp'] ?? '');
            if (!$wa || isset($seenWa[$wa])) continue;
            $seenWa[$wa] = true;
            $uniqEnv[]   = $e;
        }
        $envios = $uniqEnv;

        // Cria lote
        $pdo->prepare("
            INSERT INTO reativacao_lotes
                (company_id, criado_em, status, contexto, total_clientes, enviados, erros, mensagem_idx, observacoes)
            VALUES (?, ?, 'aguardando', 'promocao', ?, 0, 0, 0, ?)
        ")->execute([$companyId, $now, count($envios), $obs ?: $msgTitulo]);
        $loteId = (int)$pdo->lastInsertId();

        // Insere envios
        $stmtE = $pdo->prepare("
            INSERT INTO reativacao_envios
                (lote_id, client_id, company_id, whatsapp, nome, contexto, mensagem, tentativa, status)
            VALUES (?, ?, ?, ?, ?, 'promocao', ?, 1, 'pendente')
        ");
        foreach ($envios as $e) {
            $stmtE->execute([$loteId, (int)$e['client_id'], $companyId, $e['whatsapp'], $e['nome'], $e['mensagem']]);
        }

        // Registra cooldown DIÁRIO — bloqueia o número pelo resto do dia
        $stmtC = $pdo->prepare("
            INSERT INTO promocoes_cooldown (company_id, whatsapp, lote_id, enviado_em)
            VALUES (?, ?, ?, CURDATE())
            ON DUPLICATE KEY UPDATE lote_id = VALUES(lote_id)
        ");
        foreach ($envios as $e) {
            try {
                $wa = preg_replace('/\D/', '', $e['whatsapp']);
                $stmtC->execute([$companyId, $wa, $loteId]);
            } catch (Throwable $ignored) {}
        }

        echo json_encode(['ok' => true, 'lote_id' => $loteId, 'total' => count($envios)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ── 404 ── */
http_response_code(404);
echo json_encode(['error' => 'Ação não encontrada: ' . htmlspecialchars($action)]);
