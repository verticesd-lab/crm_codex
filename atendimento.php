<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_login();

?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Atendimento | CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    .cw-safe-h { height: calc(100vh - 64px); } /* header */

    #messages {
      background-image:
        radial-gradient(circle at 2px 2px, rgba(255,255,255,0.05) 1px, transparent 0);
      background-size: 24px 24px;
      scroll-behavior: smooth;
    }

    #messages::-webkit-scrollbar {
      width: 4px;
    }

    #messages::-webkit-scrollbar-thumb {
      background: #334155;
      border-radius: 10px;
    }

    #messages::-webkit-scrollbar-track {
      background: transparent;
    }

    .rounded-2xl:hover {
      filter: brightness(1.1);
      transition: filter 0.2s;
    }
  </style>
</head>

<body class="bg-slate-950 text-slate-100">
<?php require_once __DIR__ . '/views/partials/header.php'; ?>

<div class="flex">
  <?php require_once __DIR__ . '/views/partials/sidebar.php'; ?>

  <main class="flex-1 p-4">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h1 class="text-xl font-semibold">Atendimento</h1>
        <div class="text-xs text-slate-400">Central unificada (WhatsApp/Site/etc.)</div>
      </div>

      <a
        href="#"
        id="btnOpenCentral"
        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 transition"
      >
        Abrir Central (Nova Aba)
      </a>
    </div>

    <div class="grid grid-cols-12 gap-4 cw-safe-h">
      <!-- Lista -->
      <section class="col-span-4 bg-slate-900 border border-slate-800 rounded-xl overflow-hidden flex flex-col">
        <div class="p-3 border-b border-slate-800 flex items-center gap-2">
          <input
            id="q"
            class="w-full rounded-lg bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            placeholder="Buscar por telefone, e-mail ou nome..."
          />
          <button
            id="btnRefresh"
            class="px-3 py-2 text-sm rounded-lg bg-slate-800 hover:bg-slate-700"
            title="Atualizar"
          >↻</button>
        </div>

        <div id="convList" class="divide-y divide-slate-800 overflow-auto flex-1"></div>

        <div class="p-3 border-t border-slate-800 text-xs text-slate-400" id="listHint"></div>
      </section>

      <!-- Chat / Timeline -->
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
            <textarea
              id="reply"
              rows="2"
              class="flex-1 rounded-lg bg-slate-950/70 border border-slate-700 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              placeholder="Digite sua resposta..."
            ></textarea>

            <button
              id="btnSend"
              class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-sm font-semibold disabled:opacity-60 disabled:cursor-not-allowed"
            >
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
    .replaceAll('&','&amp;').replaceAll('<','&lt;')
    .replaceAll('>','&gt;').replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

async function fetchJSON(url, opt){
  const res = await fetch(url, opt || {});
  const txt = await res.text();
  try { return JSON.parse(txt); }
  catch(e){ throw new Error('JSON inválido: ' + txt); }
}

function convLabel(c){
  const name  = (c.contact_name  || '').trim();
  const phone = (c.contact_phone || '').trim();
  const email = (c.contact_email || '').trim();
  if (name) return name;
  if (phone) return phone;
  if (email) return email;
  return 'Conversa #' + (c.id || '');
}

function convSubtitle(c){
  const phone = (c.contact_phone || '').trim();
  const email = (c.contact_email || '').trim();
  if (phone && email) return phone + ' • ' + email;
  if (phone) return phone;
  if (email) return email;
  return '';
}

function renderList(rows){
  const list = el('convList');
  list.innerHTML = '';

  el('listHint').textContent = rows.length
    ? (rows.length + ' conversa(s) carregada(s).')
    : 'Nenhuma conversa encontrada.';

  if (!rows.length){
    list.innerHTML = '<div class="p-4 text-sm text-slate-400">Nenhuma conversa.</div>';
    return;
  }

  rows.forEach(c => {
    const item = document.createElement('button');
    item.type = 'button';
    item.className = 'w-full text-left p-3 hover:bg-slate-800 transition';
    const title = convLabel(c);
    const sub = convSubtitle(c);
    const last = (c.last_content || '').trim();
    const st = (c.status || '').trim();

    item.innerHTML = `
      <div class="flex items-center justify-between gap-2">
        <div class="font-semibold truncate">${escapeHtml(title)}</div>
        <div class="text-xs text-slate-400">${escapeHtml(st)}</div>
      </div>
      ${sub ? `<div class="text-xs text-slate-400 mt-1 truncate">${escapeHtml(sub)}</div>` : ''}
      ${last ? `<div class="text-sm text-slate-200 mt-2 truncate">${escapeHtml(last)}</div>` : `<div class="text-sm text-slate-500 mt-2">Sem mensagens</div>`}
      <div class="text-xs text-slate-500 mt-1">#${escapeHtml(c.id)}</div>
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
  // ✅ PRINCIPAL: aqui é o ID interno do CRM (atd_conversations.id)
  selectedConversationId = parseInt(c.id, 10);

  el('convTitle').textContent = convLabel(c);
  el('convMeta').textContent = `Conversa #${selectedConversationId}`;
  el('convStatus').textContent = (c.status || '').trim();

  await loadMessages(selectedConversationId);
}

function renderMessages(rows){
  const box = el('messages');
  box.innerHTML = '';

  if (!rows.length) {
    box.innerHTML = '<div class="text-sm text-slate-500 text-center py-10">Início da conversa.</div>';
    return;
  }

  let lastDateLabel = "";

  rows.forEach(m => {
    // --- LÓGICA DE SEPARADOR DE DATA ---
    const dateObj = new Date(m.created_at);
    // Formata para "06 de Fevereiro de 2026"
    const dateLabel = dateObj.toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' });

    if (dateLabel !== lastDateLabel) {
      const divider = document.createElement('div');
      divider.className = 'flex justify-center my-6 sticky top-0 z-10';
      divider.innerHTML = `
        <span class="bg-slate-900/90 backdrop-blur text-slate-500 text-[10px] uppercase tracking-widest px-4 py-1 rounded-full border border-slate-800 shadow-sm">
          ${dateLabel}
        </span>
      `;
      box.appendChild(divider);
      lastDateLabel = dateLabel;
    }

    // --- LÓGICA DE QUEM ENVIOU ---
    const isOutgoing = (m.direction === 'outgoing');
    // Se o sender_name contiver "IA", tratamos como robô
    const isIA = (m.sender_name && m.sender_name.toUpperCase().includes('IA'));

    const row = document.createElement('div');
    row.className = 'flex w-full mb-3 ' + (isOutgoing ? 'justify-end' : 'justify-start');

    const content = (m.content || '');
    const time = dateObj.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    // Definimos as cores baseado no papel
    let bubbleClass = "";
    let senderLabel = m.sender_name || (isOutgoing ? 'Você' : 'Cliente');

    if (!isOutgoing) {
      bubbleClass = "bg-slate-800 border-slate-700 text-slate-200 rounded-tl-none"; // Cliente
    } else if (isIA) {
      bubbleClass = "bg-indigo-950/40 border-indigo-500/30 text-indigo-100 rounded-tr-none border"; // IA
      senderLabel = "🤖 " + senderLabel;
    } else {
      bubbleClass = "bg-indigo-600 text-white rounded-tr-none"; // Humano (Você)
    }

    row.innerHTML = `
      <div class="flex flex-col ${isOutgoing ? 'items-end' : 'items-start'} max-w-[80%]">
        <div class="flex items-center gap-2 mb-1 px-1">
          <span class="text-[10px] font-bold text-slate-500 uppercase tracking-tight">${escapeHtml(senderLabel)}</span>
          <span class="text-[10px] text-slate-600">${time}</span>
        </div>

        <div class="relative px-3 py-2 rounded-2xl text-[14px] shadow-sm ${bubbleClass}">
          <div class="whitespace-pre-wrap leading-relaxed">${escapeHtml(content)}</div>
        </div>
      </div>
    `;
    box.appendChild(row);
  });

  // Scroll automático para a última mensagem
  setTimeout(() => {
    box.scrollTo({ top: box.scrollHeight, behavior: 'smooth' });
  }, 50);
}

async function loadMessages(convId){
  setStatus('Carregando histórico...');
  const j = await fetchJSON('/api/atendimento-messages.php?conversation_id=' + convId + '&limit=400');
  if (!j.ok) throw new Error(j.error || 'Falha ao carregar mensagens');

  renderMessages(j.data || []);
  setStatus('');
}

async function sendMessage(){
  if (!selectedConversationId){
    setStatus('Selecione uma conversa antes de responder.');
    return;
  }

  const content = el('reply').value.trim();
  if (!content) return;

  el('btnSend').disabled = true;
  setStatus('Enviando...');

  const j = await fetchJSON('/api/atendimento-send.php', {
    method: 'POST',
    headers: { 'Content-Type':'application/json' },
    body: JSON.stringify({
      conversation_id: selectedConversationId,
      content
    })
  });

  el('btnSend').disabled = false;

  if (!j.ok){
    setStatus('Erro ao enviar: ' + (j.error || ''));
    return;
  }

  el('reply').value = '';
  setStatus('Enviado. Atualizando histórico...');
  await loadMessages(selectedConversationId);
  await loadConversations(); // atualiza last_content / ordenação
  setStatus('');
}

// Eventos UI
el('btnRefresh').onclick = () => loadConversations().catch(err => setStatus(err.message));
el('btnSend').onclick = () => sendMessage().catch(err => setStatus(err.message));
el('q').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') loadConversations().catch(err => setStatus(err.message));
});

// Botão “Abrir Central”: se você quiser apontar pra algum lugar (Chatwoot / ActivePieces / etc)
el('btnOpenCentral').onclick = (e) => {
  e.preventDefault();
  // Coloque aqui a URL que você quer abrir em nova aba:
  // window.open('https://...', '_blank', 'noopener');
  alert('Defina a URL da central no JS (btnOpenCentral).');
};

loadConversations().catch(err => setStatus(err.message));
</script>
</body>
</html>
