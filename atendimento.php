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
    /* Container de Mensagens */
    #messages {
      background-color: #0f172a; /* Fundo levemente mais claro que o preto */
      background-image: radial-gradient(rgba(255,255,255,0.03) 1px, transparent 0);
      background-size: 20px 20px;
      display: flex;
      flex-direction: column;
      padding: 20px !important;
    }

    /* Separador de Data Estilo Chatwoot */
    .date-divider {
      display: flex;
      align-items: center;
      text-align: center;
      margin: 24px 0;
      color: #475569;
    }
    .date-divider::before, .date-divider::after {
      content: '';
      flex: 1;
      border-bottom: 1px solid #1e293b;
    }
    .date-divider span {
      padding: 0 15px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    /* Base das Mensagens */
    .msg-row {
      display: flex;
      width: 100%;
      margin-bottom: 4px; /* Espaço curto entre mensagens do mesmo autor */
    }

    .msg-bubble {
      max-width: 70%;
      padding: 10px 14px;
      font-size: 14px;
      line-height: 1.5;
      position: relative;
      box-shadow: 0 1px 2px rgba(0,0,0,0.2);
    }

    /* Lado do Cliente (Esquerda) */
    .justify-start .msg-bubble {
      background-color: #1e293b; /* Cinza azulado escuro */
      color: #f1f5f9;
      border-radius: 4px 16px 16px 16px;
    }

    /* Seu Lado / IA (Direita) */
    .justify-end .msg-bubble {
      background-color: #4f46e5; /* Indigo vibrante */
      color: #ffffff;
      border-radius: 16px 4px 16px 16px;
    }

    /* Se for IA, mudar a cor para diferenciar do humano */
    .is-ia .msg-bubble {
      background-color: #312e81; /* Roxo bem escuro */
      border: 1px solid #4338ca;
    }

    .msg-time {
      font-size: 10px;
      opacity: 0.6;
      margin-top: 4px;
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

function renderMessages(rows) {
  const box = el('messages');
  box.innerHTML = '';

  let lastDate = "";
  let lastSender = "";

  rows.forEach(m => {
    const dateObj = new Date(m.created_at);
    const dateLabel = dateObj.toLocaleDateString('pt-BR', { day: '2-digit', month: 'long' });
    const isOutgoing = (m.direction === 'outgoing');
    const isIA = (m.sender_name && m.sender_name.toUpperCase().includes('IA'));

    // --- SEPARADOR DE DATA ---
    if (dateLabel !== lastDate) {
      const div = document.createElement('div');
      div.className = 'date-divider';
      div.innerHTML = `<span>${dateLabel}</span>`;
      box.appendChild(div);
      lastDate = dateLabel;
    }

    // --- LINHA DA MENSAGEM ---
    const row = document.createElement('div');
    row.className = `msg-row ${isOutgoing ? 'justify-end is-ia' : 'justify-start'}`;
    if (!isIA && isOutgoing) row.classList.remove('is-ia');
    
    const time = dateObj.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    // Só mostra o nome se o autor mudar (estilo WhatsApp/Chatwoot)
    const showName = (m.sender_name !== lastSender);
    lastSender = m.sender_name;

    row.innerHTML = `
      <div class="flex flex-col ${isOutgoing ? 'items-end' : 'items-start'}">
        ${showName ? `<span class="text-[10px] text-slate-500 mb-1 font-bold uppercase ml-1 mr-1">${isIA ? '🤖 IA' : m.sender_name || (isOutgoing ? 'Você' : 'Cliente')}</span>` : ''}
        <div class="msg-bubble">
          <div class="whitespace-pre-wrap">${escapeHtml(m.content)}</div>
          <div class="msg-time ${isOutgoing ? 'text-right' : 'text-left'}">${time}</div>
        </div>
      </div>
    `;
    
    box.appendChild(row);
  });

  box.scrollTop = box.scrollHeight;
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
