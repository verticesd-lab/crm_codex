// Chat guiado da loja: abre o painel, responde os assuntos e monta a mensagem do WhatsApp.
document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('fm-chat');
  if (!root) return;

  const toggle = document.getElementById('fm-chat-toggle');
  const panel = document.getElementById('fm-chat-panel');
  const log = document.getElementById('fm-chat-log');
  const waLink = document.getElementById('fm-chat-wa');
  const orderLink = document.getElementById('fm-chat-order');
  const { whats, store, product, cart } = root.dataset;

  const setOpen = (open) => {
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
    const target = open ? panel.querySelector('.fm-chat-topics button') : toggle;
    if (target) target.focus();
  };

  const say = (text, fromUser) => {
    const msg = document.createElement('p');
    msg.className = fromUser ? 'fm-chat-msg is-user' : 'fm-chat-msg';
    msg.textContent = text; // textContent: nunca interpreta HTML
    log.appendChild(msg);
    log.scrollTop = log.scrollHeight;
  };

  const setTopic = (topic) => {
    if (!waLink || !whats) return;
    const parts = ['Olá! Vim da loja online da ' + store + '.'];
    if (topic) parts.push('Assunto: ' + topic + '.');
    if (product) parts.push('Produto: ' + product + '.');
    waLink.href = 'https://wa.me/' + whats + '?text=' + encodeURIComponent(parts.join(' '));
  };

  // O link direto do WhatsApp vira o botão de abrir o chat quando o JS está ativo.
  toggle.addEventListener('click', (event) => {
    event.preventDefault();
    setOpen(panel.hidden);
  });
  document.getElementById('fm-chat-close').addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) setOpen(false);
  });

  panel.querySelectorAll('.fm-chat-topics button').forEach((button) => {
    button.addEventListener('click', () => {
      say(button.textContent, true);
      say(button.dataset.answer, false);
      setTopic(button.textContent);
    });
  });

  if (orderLink) {
    orderLink.addEventListener('click', (event) => {
      if (Number(cart) > 0) return;
      event.preventDefault();
      say('Seu carrinho está vazio. Adicione os produtos e volte aqui para fechar o pedido, ou continue pelo WhatsApp.', false);
    });
  }

  setTopic('');
});
