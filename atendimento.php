<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login(); // <-- usa user_id + company_id (certo)

// CONFIG: coloque seu link do inbox
$CHATWOOT_INBOX_URL = 'https://chat.formenstore.com.br/app/accounts/1/inbox/1';

// Se quiser abrir automaticamente ao entrar na página, descomente:
// redirect($CHATWOOT_INBOX_URL);
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Atendimento | CRM</title>

  <script src="https://cdn.tailwindcss.com"></script>
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

      <div class="rounded-xl border border-slate-800 bg-slate-900 p-6">
        <p class="text-sm text-slate-300">
          Para segurança do navegador, o Chatwoot não pode ser embutido dentro do CRM via iframe.
          Clique no botão abaixo para abrir a central em nova aba.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
          <a
            href="<?= sanitize($CHATWOOT_INBOX_URL) ?>"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition"
          >
            Abrir Central de Atendimento
          </a>

          <a
            href="<?= sanitize($CHATWOOT_INBOX_URL) ?>"
            class="inline-flex items-center justify-center rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800 transition"
          >
            Abrir nesta aba
          </a>
        </div>

        <div class="mt-4 text-xs text-slate-400">
          Dica: se você quiser abrir automaticamente ao entrar nesta tela, descomente a linha:
          <code class="px-1 py-0.5 bg-slate-950 border border-slate-800 rounded">redirect($CHATWOOT_INBOX_URL);</code>
        </div>
      </div>

      <!-- Próximo passo (integração com WhatsApp + automações) virá aqui -->
    </main>
  </div>

  <?php require_once __DIR__ . '/views/partials/footer.php'; ?>
</body>
</html>
