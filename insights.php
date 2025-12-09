<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

// Geração simples de insights mockados com base nos campos informados
function generate_insights(string $tipo, string $nome, string $url, string $nota): array
{
    $bullets = [];
    $contexto = $tipo === 'concorrente' ? 'Concorrente' : 'Cliente';
    $bullets[] = "$contexto observado: " . ($nome ?: 'Sem nome informado');
    if ($url) $bullets[] = "Fonte analisada: $url";
    if ($nota) $bullets[] = "Ponto destacado: $nota";
    $bullets[] = $tipo === 'concorrente'
        ? 'Oportunidade: posicione-se com oferta rápida + prova social (stories/WhatsApp).'
        : 'Ação sugerida: enviar follow-up personalizado com CTA direto para WhatsApp.';
    $bullets[] = 'Teste A/B: headline de urgência vs. benefício concreto na próxima campanha.';
    return $bullets;
}

$insights = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = $_POST['tipo'] ?? 'cliente';
    $nome = trim($_POST['nome'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $nota = trim($_POST['nota'] ?? '');
    $insights = generate_insights($tipo, $nome, $url, $nota);
}

include __DIR__ . '/views/partials/header.php';
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
        <h1 class="text-2xl font-semibold mb-2">Insights de IA</h1>
        <p class="text-sm text-slate-600 mb-4">Analise concorrentes ou clientes e gere pontos rápidos de ação.</p>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Tipo</label>
                <select name="tipo" class="w-full rounded border-slate-300">
                    <option value="cliente">Cliente</option>
                    <option value="concorrente" <?= (($_POST['tipo'] ?? '') === 'concorrente') ? 'selected' : '' ?>>Concorrente</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Nome</label>
                <input name="nome" value="<?= sanitize($_POST['nome'] ?? '') ?>" class="w-full rounded border-slate-300" placeholder="Ex.: Loja Exemplo">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">URL / perfil</label>
                <input name="url" value="<?= sanitize($_POST['url'] ?? '') ?>" class="w-full rounded border-slate-300" placeholder="Site, Instagram ou referência">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-slate-600 mb-1">Notas / observações</label>
                <textarea name="nota" class="w-full rounded border-slate-300" rows="3" placeholder="O que observar ou objetivo da análise"><?= sanitize($_POST['nota'] ?? '') ?></textarea>
            </div>
            <div class="md:col-span-2">
                <button class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Gerar insights</button>
            </div>
        </form>
        <?php if ($insights): ?>
            <div class="mt-6 space-y-2">
                <h2 class="text-lg font-semibold">Sugestões</h2>
                <ul class="list-disc pl-5 text-slate-700 space-y-1">
                    <?php foreach ($insights as $item): ?>
                        <li><?= sanitize($item) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-xs text-slate-500">Observação: insights gerados localmente. Conecte um agente externo via API para análises avançadas.</p>
            </div>
        <?php endif; ?>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-3">
        <p class="text-sm text-slate-500">Automatize com IA</p>
        <h3 class="text-lg font-semibold">Como integrar</h3>
        <p class="text-sm text-slate-600">Use a API token e endpoints de CRM para que um agente (GPT Builder/Activepieces) leia e escreva contatos e interações.</p>
        <div class="text-xs font-mono bg-slate-100 border border-slate-200 rounded p-3 break-words"><?= sanitize(API_TOKEN_IA) ?></div>
        <ul class="text-xs text-slate-600 list-disc pl-4 space-y-1">
            <li>GET /api/client-search.php (telefone/instagram)</li>
            <li>POST /api/client-interaction-create.php (registrar contato)</li>
            <li>POST /api/client-create-or-update.php (atualizar perfil)</li>
        </ul>
        <p class="text-xs text-slate-500">Dica: use tags para marcar campanha ou concorrente analisado.</p>
    </div>
</div>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
