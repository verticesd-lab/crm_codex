<?php
/**
 * Chat de atendimento guiado da loja (sem backend).
 * Espera: $company, $slug, $cartKey. Opcionais: $chatProductName, $chatHideOrder.
 * Sem JavaScript, o botão continua sendo o link direto do WhatsApp.
 */
$chatWhats = preg_replace('/\D+/', '', (string)($company['whatsapp_principal'] ?? ''));
$chatStore = (string)($company['nome_fantasia'] ?? 'Loja');
$chatCartCount = (int)array_sum($_SESSION[$cartKey] ?? []);
$chatOffersUrl = BASE_URL . '/ofertas.php?empresa=' . urlencode($slug);
$chatHandoff = 'Esse assunto é com nosso atendente. Toque em "Continuar no WhatsApp" que respondemos por lá.';

// EDITE AQUI as respostas do chat. Texto simples, uma resposta por assunto.
$chatTopics = [
    'Tamanhos' => 'Os tamanhos disponíveis aparecem em cada produto. Ficou em dúvida sobre a medida? Chame no WhatsApp que ajudamos a escolher.',
    'Entrega e retirada' => $chatHandoff,
    'Pagamento' => 'O pagamento é combinado e finalizado diretamente com o atendente pelo WhatsApp.',
    'Trocas' => $chatHandoff,
    'Ofertas' => 'As ofertas da semana ficam na página de Ofertas: ' . $chatOffersUrl,
    'Horário e endereço' => $chatHandoff,
];

// ponytail: dados da For Men Store (empresa 1, mesma convenção de landing.php) fixos aqui;
// viram campos em Configurações se outras lojas precisarem de respostas próprias.
if ((int)($company['id'] ?? 0) === 1) {
    $chatTopics['Entrega e retirada'] = 'Temos entrega disponível, e você também pode reservar pelo WhatsApp e retirar na loja. Prazo e valor da entrega são combinados com o atendente.';
    $chatTopics['Pagamento'] = 'Aceitamos Pix, cartão ou dinheiro. O pagamento é combinado e finalizado diretamente com o atendente pelo WhatsApp.';
    $chatTopics['Horário e endereço'] = 'Atendemos de segunda a sábado, das 8h30 às 19h. Avenida Goiânia, 1913 - Parque Residencial Buriti, Rondonópolis - MT.';
}

$chatWaUrl = $chatWhats ? 'https://wa.me/' . $chatWhats . '?text=' . rawurlencode('Olá, vim da loja online!') : '';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/store-chat.css">
<div class="fm-chat" id="fm-chat"
     data-whats="<?= sanitize($chatWhats) ?>"
     data-store="<?= sanitize($chatStore) ?>"
     data-product="<?= sanitize((string)($chatProductName ?? '')) ?>"
     data-cart="<?= $chatCartCount ?>">
    <a class="fm-chat-toggle" id="fm-chat-toggle" href="<?= sanitize($chatWaUrl ?: '#') ?>" <?= $chatWaUrl ? 'target="_blank" rel="noopener"' : '' ?> aria-controls="fm-chat-panel" aria-expanded="false">
        <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/></svg>
        <span>Atendimento</span>
    </a>

    <section class="fm-chat-panel" id="fm-chat-panel" role="dialog" aria-label="Atendimento <?= sanitize($chatStore) ?>" hidden>
        <header class="fm-chat-head">
            <div>
                <strong>Atendimento</strong>
                <small><?= sanitize($chatStore) ?> · resposta na hora</small>
            </div>
            <button type="button" id="fm-chat-close" aria-label="Fechar atendimento">✕</button>
        </header>

        <div class="fm-chat-log" id="fm-chat-log" aria-live="polite">
            <p class="fm-chat-msg">Olá! Sobre o que você quer saber?<?= !empty($chatProductName) ? ' Vi que você está olhando: ' . sanitize($chatProductName) . '.' : '' ?></p>
        </div>

        <div class="fm-chat-topics" aria-label="Assuntos">
            <?php foreach ($chatTopics as $topic => $answer): ?>
                <button type="button" data-answer="<?= sanitize($answer) ?>"><?= sanitize($topic) ?></button>
            <?php endforeach; ?>
        </div>

        <footer class="fm-chat-actions">
            <?php if ($chatWaUrl): ?>
                <a class="fm-chat-wa" id="fm-chat-wa" href="<?= sanitize($chatWaUrl) ?>" target="_blank" rel="noopener">Continuar no WhatsApp</a>
            <?php endif; ?>
            <?php if (empty($chatHideOrder)): ?>
                <a class="fm-chat-order" id="fm-chat-order" href="<?= BASE_URL ?>/checkout.php?empresa=<?= urlencode($slug) ?>">Fechar pedido</a>
            <?php endif; ?>
        </footer>
    </section>
</div>
<script src="<?= BASE_URL ?>/assets/js/store-chat.js" defer></script>
