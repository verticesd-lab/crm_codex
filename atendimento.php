<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

<<<<<<< HEAD
require_login(); // <-- usa user_id + company_id (certo)

// CONFIG: coloque seu link do inbox
$CHATWOOT_INBOX_URL = 'https://chat.formenstore.com.br/app/accounts/1/inbox/1';

// Se quiser abrir automaticamente ao entrar na página, descomente:
// redirect($CHATWOOT_INBOX_URL);
=======
require_login();
>>>>>>> ee13660 (feat: atendimento engine (conversas, mensagens e envio))
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Atendimento | CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
<<<<<<< HEAD
=======

  <style>
    .h-screen-safe { height: calc(100vh - 64px); } /* header */
  </style>
>>>>>>> ee13660 (feat: atendimento engine (conversas, mensagens e envio))
</head>

<body class="bg-slate-950 text-slate-100">
<?php require_once __DIR__ . '/views/partials/header.php'; ?>

<div class="flex">
  <?php require_once __DIR__ . '/views/partials/sidebar.php'; ?>

  <main class="flex-1 p-4">
    <div class="flex items-center justify-between mb-3">
      <h1 class="text-xl font-semibold">Atendimento</h1>
      <div class="text-xs text-slate-400">Central unificada (Site/WhatsApp/etc.)</div>
    </div>

    <div class="grid grid-cols-12 gap-4">
      <!-- Lista -->
      <section class="col-span-4 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
        <div class="p-3 border-b border-slate-800 flex items-center gap-2">
          <input id="q" class="w-full rounded-lg bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm"
                 placeholder="Buscar por telefone, e-mail..." />
          <button id="btnRefresh" class="px-3 py-2 text-sm rounded-lg bg-slate-800 hover:bg-slate-700">↻</button>
        </div>
        <div id="convList" class="divide-y divide-slate-800 max-h-[70vh] overflow-auto"></div>
      </section>

      <!-- Chat -->
      <section class="col-span-8 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden flex flex-col">
        <div class="p-3 border-b border-slate-800 flex items-center justify-between">
          <div>
            <div id="convTitle" class="font-semibold">Selecione uma conversa</div>
            <div id="convMeta" class="text-xs text-slate-400"></div>
          </div>
          <button id="btnOpenWidget"
                  class="hidden px-3 py-2 text-sm rounded-lg bg-indigo-600 hover:bg-indigo-500">
            Abrir modo rápido
          </button>
        </div>

        <div id="messages" class="p-3 space-y-2 flex-1 overflow-auto"></div>

        <div class="p-3 border-t border-slate-800">
          <div class="flex gap-2">
            <textarea id="reply" rows="2"
              class="flex-1 rounded-lg bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm"
              placeholder="Digite sua resposta..."></textarea>
            <button id="btnSend"
              class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-sm font-semibold">
              Enviar
            </button>
          </div>
          <div id="status" class="mt-2 text-xs text-slate-400"></div>
        </div>
      </section>
    </div>
  </main>
</div>

<?php require_once __DIR__ . '/views/partials/footer.php'; ?>

<script>
let selectedConversationId = null;

function el(id){ return document.getElementById(id); }

function setStatus(msg){ el('status').textContent = msg || ''; }

async function fetchJSON(url, opt){
  const res = await fetch(url, opt || {});
  const txt = await res.text();
  try { return JSON.parse(txt); } catch(e){ throw new Error('JSON inválido: ' + txt); }
}

function convLabel(c){
  const phone = (c.phone || '').trim();
  const email = (c.email || '').trim();
  if (phone) return phone;
  if (email) return email;
  return 'Conversa #' + c.chatwoot_conversation_id;
}

function renderList(rows){
  const list = el('convList');
  list.innerHTML = '';
  if (!rows.length){
    list.innerHTML = '<div class="p-4 text-sm text-slate-400">Nenhuma conversa.</div>';
    return;
  }
  rows.forEach(c => {
    const item = document.createElement('button');
    item.className = 'w-full text-left p-3 hover:bg-slate-800 transition';
    item.innerHTML = `
      <div class="flex items-center justify-between">
        <div class="font-semibold">${escapeHtml(convLabel(c))}</div>
        <div class="text-xs text-slate-400">${escapeHtml((c.status || ''))}</div>
      </div>
<<<<<<< HEAD

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
=======
      <div class="text-xs text-slate-400 mt-1">
        #${c.chatwoot_conversation_id} • inbox ${c.chatwoot_inbox_id || '-'}
>>>>>>> ee13660 (feat: atendimento engine (conversas, mensagens e envio))
      </div>
    `;
    item.onclick = () => openConversation(c);
    list.appendChild(item);
  });
}

<<<<<<< HEAD
      <!-- Próximo passo (integração com WhatsApp + automações) virá aqui -->
    </main>
  </div>
=======
function escapeHtml(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}
>>>>>>> ee13660 (feat: atendimento engine (conversas, mensagens e envio))

async function loadConversations(){
  setStatus('Carregando conversas...');
  const q = el('q').value.trim();
  const url = '/api/atendimento-conversations.php?limit=80' + (q ? '&q=' + encodeURIComponent(q) : '');
  const j = await fetchJSON(url);
  if (!j.ok) throw new Error(j.error || 'Falha ao listar conversas');
  renderList(j.data || []);
  setStatus('');
}

async function openConversation(c){
  selectedConversationId = parseInt(c.chatwoot_conversation_id, 10);
  el('convTitle').textContent = convLabel(c);
  el('convMeta').textContent = `Conversa #${selectedConversationId} • Inbox ${c.chatwoot_inbox_id || '-'}`;

  el('btnOpenWidget').classList.remove('hidden'); // modo rápido opcional

  await loadMessages(selectedConversationId);
}

async function loadMessages(convId){
  setStatus('Carregando mensagens...');
  const j = await fetchJSON('/api/atendimento-messages.php?conversation_id=' + convId + '&limit=400');
  if (!j.ok) throw new Error(j.error || 'Falha ao carregar mensagens');

  const box = el('messages');
  box.innerHTML = '';

  (j.data || []).forEach(m => {
    const mine = (m.direction === 'outgoing');
    const row = document.createElement('div');
    row.className = 'flex ' + (mine ? 'justify-end' : 'justify-start');

    row.innerHTML = `
      <div class="${mine ? 'bg-indigo-600/20 border-indigo-500/30' : 'bg-slate-950/50 border-slate-700'} border rounded-xl px-3 py-2 max-w-[85%]">
        <div class="text-xs text-slate-400 mb-1">${escapeHtml(m.sender_name || (mine ? 'Você' : 'Cliente'))}</div>
        <div class="text-sm whitespace-pre-wrap">${escapeHtml(m.content || '')}</div>
      </div>
    `;
    box.appendChild(row);
  });

  box.scrollTop = box.scrollHeight;
  setStatus('');
}

async function sendMessage(){
  if (!selectedConversationId){
    setStatus('Selecione uma conversa antes de responder.');
    return;
  }
  const content = el('reply').value.trim();
  if (!content) return;

  setStatus('Enviando...');
  el('btnSend').disabled = true;

  const j = await fetchJSON('/api/atendimento-send.php', {
    method: 'POST',
    headers: { 'Content-Type':'application/json' },
    body: JSON.stringify({ conversation_id: selectedConversationId, content })
  });

  el('btnSend').disabled = false;

  if (!j.ok){
    setStatus('Erro ao enviar: ' + (j.error || ''));
    return;
  }

  el('reply').value = '';
  setStatus('Enviado. Atualizando...');
  // Você pode também inserir otimisticamente no DOM
  await loadMessages(selectedConversationId);
  setStatus('');
}

/**
 * MODO RÁPIDO (Opcional)
 * Abre o widget público (não /app) para responder rápido.
 * Ele pode ficar com launcher escondido e só abrir quando clicar.
 */
(function initWidget(){
  const baseUrl = "<?= sanitize(rtrim(CHATWOOT_BASE_URL, '/')) ?>";
  const websiteToken = "<?= sanitize((string)($_ENV['CHATWOOT_WEBSITE_TOKEN'] ?? '')) ?>"; // se você quiser usar env
  // Se não tiver token em env, você pode setar fixo aqui (não recomendo) ou deixar desligado.
  if (!websiteToken) return;

  window.chatwootSettings = {
    hideMessageBubble: true,
    position: "right",
    locale: "pt_BR",
  };

  (function(d,t) {
    var BASE_URL=baseUrl;
    var g=d.createElement(t), s=d.getElementsByTagName(t)[0];
    g.src=BASE_URL+"/packs/js/sdk.js";
    g.defer = true;
    g.async = true;
    s.parentNode.insertBefore(g,s);
    g.onload=function(){
      window.chatwootSDK.run({
        websiteToken: websiteToken,
        baseUrl: BASE_URL
      });
    }
  })(document,"script");

  el('btnOpenWidget').onclick = () => {
    if (window.$chatwoot) window.$chatwoot.toggle(); // abre/fecha
  };
})();

el('btnRefresh').onclick = loadConversations;
el('btnSend').onclick = sendMessage;
el('q').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') loadConversations();
});

loadConversations().catch(err => setStatus(err.message));
</script>
</body>
</html>
