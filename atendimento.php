<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

// Link do Chatwoot (central /app) — só abre em NOVA ABA
$CHATWOOT_INBOX_URL = 'https://chat.formenstore.com.br/app/accounts/1/inbox/1';
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
      <div>
        <h1 class="text-xl font-semibold">Atendimento</h1>
        <div class="text-xs text-slate-400">Central unificada (Site/WhatsApp/etc.)</div>
      </div>

      <div class="flex items-center gap-2">
        <a
          href="<?= sanitize($CHATWOOT_INBOX_URL) ?>"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition"
        >
          Abrir Central (Nova Aba)
        </a>
      </div>
    </div>

    <div class="grid grid-cols-12 gap-4">
      <!-- Lista -->
      <section class="col-span-4 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
        <div class="p-3 border-b border-slate-800 flex items-center gap-2">
          <input id="q"
                 class="w-full rounded-lg bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm"
                 placeholder="Buscar por telefone, e-mail..." />
          <button id="btnRefresh"
                  class="px-3 py-2 text-sm rounded-lg bg-slate-800 hover:bg-slate-700">↻</button>
        </div>
        <div id="convList" class="divide-y divide-slate-800 max-h-[72vh] overflow-auto"></div>
      </section>

      <!-- Chat -->
      <section class="col-span-8 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden flex flex-col">
        <div class="p-3 border-b border-slate-800 flex items-center justify-between">
          <div>
            <div id="convTitle" class="font-semibold">Selecione uma conversa</div>
            <div id="convMeta" class="text-xs text-slate-400"></div>
          </div>
          <div class="text-xs text-slate-400" id="convStatus"></div>
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

function escapeHtml(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

async function fetchJSON(url, opt){
  const res = await fetch(url, opt || {});
  const txt = await res.text();
  try { return JSON.parse(txt); }
  catch(e){ throw new Error('JSON inválido: ' + txt); }
}

function convLabel(c){
  const phone = (c.phone || '').trim();
  const email = (c.email || '').trim();
  if (phone) return phone;
  if (email) return email;
  return 'Conversa #' + (c.chatwoot_conversation_id || '');
}

function renderList(rows){
  const list = el('convList');
  list.innerHTML = '';

  if (!rows || !rows.length){
    list.innerHTML = '<div class="p-4 text-sm text-slate-400">Nenhuma conversa.</div>';
    return;
  }

  rows.forEach(c => {
    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'w-full text-left p-3 hover:bg-slate-800 transition';

    const title = escapeHtml(convLabel(c));
    const status = escapeHtml(c.status || '');
    const meta = `#${escapeHtml(c.chatwoot_conversation_id || '')} • inbox ${escapeHtml(c.chatwoot_inbox_id || '-')}`;

    item.innerHTML = `
      <div class="flex items-center justify-between">
        <div class="font-semibold">${title}</div>
        <div class="text-xs text-slate-400">${status}</div>
      </div>
      <div class="text-xs text-slate-400 mt-1">${meta}</div>
    `;

    item.onclick = () => openConversation(c);
    list.appendChild(item);
  });
}

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
  el('convStatus').textContent = (c.status || '');

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
  await loadMessages(selectedConversationId);
  setStatus('');
}

el('btnRefresh').onclick = loadConversations;
el('btnSend').onclick = sendMessage;

el('q').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') loadConversations();
});

loadConversations().catch(err => setStatus(err.message));
</script>
</body>
</html>
