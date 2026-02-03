<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login(); // <-- usa user_id + company_id (certo)

// CONFIG: coloque seu link do inbox
$CHATWOOT_INBOX_URL = 'https://chat.formenstore.com.br/app/accounts/1/inbox/1';
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Atendimento | CRM</title>

  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    /* ocupa a tela toda com aparência "nativa" */
    .cw-frame { width: 100%; height: calc(100vh - 64px); border: 0; }
  </style>
</head>

<body class="bg-slate-950 text-slate-100">
  <?php require_once __DIR__ . '/views/partials/header.php'; ?>

  <div class="flex">
    <?php require_once __DIR__ . '/views/partials/sidebar.php'; ?>

    <main class="flex-1 p-4">
      <div class="flex items-center justify-between mb-3">
        <h1 class="text-xl font-semibold">Atendimento</h1>
        <div class="text-xs text-slate-400">
          Central unificada (Site/WhatsApp/etc.)
        </div>
      </div>

      <div class="rounded-xl overflow-hidden border border-slate-800 bg-slate-900">
        <iframe
          src="<?= htmlspecialchars($CHATWOOT_INBOX_URL, ENT_QUOTES, 'UTF-8'); ?>"
          class="cw-frame"
          allow="clipboard-read; clipboard-write; microphone"
        ></iframe>
      </div>

      <!-- Próximo passo (painel do cliente) virá aqui -->
    </main>
  </div>

  <?php require_once __DIR__ . '/views/partials/footer.php'; ?>
</body>
</html>
