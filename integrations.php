<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo = get_pdo();
$companyId = current_company_id();

$stmt = $pdo->prepare('SELECT nome_fantasia, slug, whatsapp_principal, instagram_usuario FROM companies WHERE id=?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$whats = trim($company['whatsapp_principal'] ?? '');
$insta = trim($company['instagram_usuario'] ?? '');
$storeLink = '/loja.php?empresa=' . urlencode($company['slug']);
$promoLink = '/promotions.php';
$whatsLink = $whats ? 'https://api.whatsapp.com/send?phone=' . urlencode($whats) . '&text=Ola%2C%20cheguei%20pelo%20painel!' : null;
$instaLink = $insta ? 'https://instagram.com/' . ltrim($insta, '@') : null;
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$apiBase = $proto . $host . '/api';

include __DIR__ . '/views/partials/header.php';
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-4">
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-slate-500">WhatsApp</p>
                    <h1 class="text-2xl font-semibold">Atender pelo WhatsApp</h1>
                    <p class="text-sm text-slate-600 mt-1">Defina o numero em Configuracoes da Empresa e copie o link para usar em conectores externos.</p>
                    <a class="inline-block mt-2 text-sm text-indigo-600 hover:underline" href="/settings.php">Ir para Configuracoes da Empresa</a>
                </div>
                <?php if ($whatsLink): ?>
                    <a target="_blank" class="px-4 py-2 rounded-full bg-emerald-600 text-white hover:bg-emerald-700" href="<?= $whatsLink ?>">Abrir WhatsApp</a>
                <?php else: ?>
                    <a class="px-4 py-2 rounded-full bg-indigo-100 text-indigo-700 border border-indigo-200" href="/settings.php">Configurar numero</a>
                <?php endif; ?>
            </div>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                    <p class="text-sm text-slate-500">Numero principal (E.164)</p>
                    <p class="text-lg font-semibold"><?= sanitize($whats ?: 'Configure em Configuracoes') ?></p>
                    <?php if ($whatsLink): ?>
                        <button data-copy="<?= $whatsLink ?>" class="mt-2 text-sm text-indigo-600 hover:underline">Copiar link de atendimento</button>
                    <?php endif; ?>
                </div>
                <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                    <p class="text-sm text-slate-500">Mensagem rapida</p>
                    <p class="text-sm text-slate-600">"Ola, cheguei pelo painel. Pode me ajudar?"</p>
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-500">Nota: o WhatsApp Web nao permite embed em iframe; os links abrem em nova aba.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-slate-500">Instagram</p>
                    <h2 class="text-xl font-semibold">Abrir perfil e DMs</h2>
                    <p class="text-sm text-slate-600 mt-1">Acesse o perfil rapidamente para responder mensagens.</p>
                    <a class="inline-block mt-2 text-sm text-indigo-600 hover:underline" href="/settings.php">Editar usuario na empresa</a>
                </div>
                <?php if ($instaLink): ?>
                    <a target="_blank" class="px-4 py-2 rounded-full bg-slate-900 text-white hover:bg-slate-800" href="<?= $instaLink ?>">Abrir Instagram</a>
                <?php else: ?>
                    <a class="px-4 py-2 rounded-full bg-indigo-100 text-indigo-700 border border-indigo-200" href="/settings.php">Configurar Instagram</a>
                <?php endif; ?>
            </div>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                    <p class="text-sm text-slate-500">@usuario</p>
                    <p class="text-lg font-semibold"><?= sanitize($insta ?: 'Configure em Configuracoes') ?></p>
                    <?php if ($instaLink): ?>
                        <button data-copy="<?= $instaLink ?>" class="mt-2 text-sm text-indigo-600 hover:underline">Copiar link do perfil</button>
                    <?php endif; ?>
                </div>
                <div class="p-4 rounded-lg border border-slate-200 bg-slate-50">
                    <p class="text-sm text-slate-500">DM rapida (mobile)</p>
                    <?php if ($insta): ?>
                        <p class="text-sm text-slate-600">Use o app Instagram e busque por <?= sanitize($insta) ?>.</p>
                    <?php else: ?>
                        <p class="text-sm text-slate-600">Adicione o usuario para liberar.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
            <p class="text-sm text-slate-500">Loja e LP</p>
            <h3 class="text-lg font-semibold">Links rapidos</h3>
            <div class="mt-3 space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span>Loja publica</span>
                    <a target="_blank" class="text-indigo-600 hover:underline" href="<?= $storeLink ?>">Abrir</a>
                </div>
                <div class="flex items-center justify-between">
                    <span>Promocoes</span>
                    <a class="text-indigo-600 hover:underline" href="<?= $promoLink ?>">Gerenciar</a>
                </div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-3">
            <p class="text-sm text-slate-500">Base da API</p>
            <h3 class="text-lg font-semibold">Conectar agentes e integracoes</h3>
            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <div class="flex-1 text-xs font-mono bg-slate-100 border border-slate-200 rounded p-3"><?= sanitize($apiBase) ?></div>
                    <button data-copy="<?= $apiBase ?>" class="px-3 py-2 text-xs rounded border border-slate-200 text-slate-700">Copiar base</button>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex-1 text-xs font-mono bg-slate-100 border border-slate-200 rounded p-3"><?= sanitize(API_TOKEN_IA) ?></div>
                    <button data-copy="<?= API_TOKEN_IA ?>" class="px-3 py-2 text-xs rounded border border-slate-200 text-slate-700">Copiar token</button>
                </div>
            </div>
            <p class="text-xs text-slate-500">Exemplos de endpoints:</p>
            <ul class="text-xs text-slate-600 list-disc pl-4 space-y-1">
                <li>GET <?= sanitize($apiBase) ?>/client-search.php?token=TOKEN&company_id=<?= $companyId ?>&phone=</li>
                <li>POST <?= sanitize($apiBase) ?>/client-create-or-update.php</li>
                <li>POST <?= sanitize($apiBase) ?>/client-interaction-create.php</li>
                <li>POST <?= sanitize($apiBase) ?>/order-create-from-chat.php</li>
                <li>GET <?= sanitize($apiBase) ?>/products-list.php?token=TOKEN&company_id=<?= $companyId ?></li>
            </ul>
            <div class="text-xs text-slate-600 space-y-1 mt-2">
                <p><span class="font-semibold">Header opcional:</span> Authorization: Bearer TOKEN</p>
                <p><span class="font-semibold">Content-Type:</span> application/json</p>
            </div>
            <div class="mt-3 text-xs text-slate-600 space-y-1">
                <p class="font-semibold">Guia rapido:</p>
                <p>1) Sempre envie <code>company_id</code> e <code>token</code> (query ou header).</p>
                <p>2) Para buscar: GET client-search com phone ou instagram.</p>
                <p>3) Para salvar/atualizar: POST client-create-or-update (JSON).</p>
                <p>4) Para registrar atendimento: POST client-interaction-create (canal, origem, titulo, resumo).</p>
                <p>5) Para pedido IA: POST order-create-from-chat (client_id, itens).</p>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm space-y-3">
            <p class="text-sm text-slate-500">Autonomo</p>
            <h3 class="text-lg font-semibold">GPT Builder / Acoes HTTP</h3>
            <p class="text-sm text-slate-600">No GPT Builder (ou Postman/Make/Zapier), configure uma acao HTTP com o token acima.</p>
            <p class="text-xs text-slate-500">Campos chave:</p>
            <ul class="text-xs text-slate-600 list-disc pl-4 space-y-1">
                <li>URL: <?= sanitize($apiBase) ?>/client-search.php (GET) ou outro endpoint</li>
                <li>Query ou header: token=<?= sanitize(API_TOKEN_IA) ?></li>
                <li>Body JSON (POST): conforme endpoint (ex.: company_id, phone/instagram, titulo, resumo, itens)</li>
            </ul>
            <p class="text-xs text-slate-500">Teste rapido (curl):</p>
            <pre class="text-[11px] bg-slate-900 text-slate-100 rounded p-3 overflow-x-auto">curl -X GET "<?= sanitize($apiBase) ?>/client-search.php?token=<?= sanitize(API_TOKEN_IA) ?>&company_id=<?= $companyId ?>&phone=5511999999999"</pre>
            <p class="text-xs text-slate-500">Exemplo de body JSON (POST interaction-create):</p>
            <pre class="text-[11px] bg-slate-900 text-slate-100 rounded p-3 overflow-x-auto">{
  "token": "<?= sanitize(API_TOKEN_IA) ?>",
  "company_id": <?= $companyId ?>,
  "client_id": 1,
  "canal": "whatsapp",
  "origem": "ia",
  "titulo": "Duvida inicial",
  "resumo": "Cliente pediu informacoes do produto X",
  "atendente": "IA"
}</pre>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
            <p class="text-sm text-slate-500">Automacao</p>
            <h3 class="text-lg font-semibold">Activepieces (exemplo)</h3>
            <div class="mt-3 space-y-2 text-sm text-slate-600">
                <p>1. Trigger: Webhook recebe dados (telefone/instagram, canal, resumo).</p>
                <p>2. HTTP GET: <?= sanitize($apiBase) ?>/client-search.php?token=TOKEN&company_id=<?= $companyId ?>&phone={{phone}}</p>
                <p>3. Se encontrou, HTTP POST interaction-create (canal, origem=activepieces, titulo, resumo).</p>
                <p>4. Se nao encontrou, HTTP POST client-create-or-update (nome + telefone/instagram).</p>
                <p>5. Opcional: HTTP POST order-create-from-chat.php para gerar pedido a partir do atendimento IA.</p>
                <p class="text-xs text-slate-500">Envie sempre company_id e token; conteudo em JSON.</p>
                <div class="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded p-3 mt-2 space-y-1">
                    <p class="font-semibold text-slate-700">Mapeamento sugerido (Activepieces):</p>
                    <p>- Query: token (valor fixo), company_id (valor fixo <?= $companyId ?>), phone (variavel do trigger)</p>
                    <p>- Body POST: titulo/resumo (texto do trigger), canal (ex.: whatsapp), origem (ex.: activepieces), client_id (retorno da busca ou criado)</p>
                    <p>- Para pedido: itens = lista [{"product_id":ID,"quantidade":1}]</p>
                </div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm">
            <p class="text-sm text-slate-500">MCP</p>
            <h3 class="text-lg font-semibold">Activepieces MCP (tools)</h3>
            <div class="mt-3 space-y-2 text-sm text-slate-600">
                <p>1. Crie um flow no Activepieces e exponha como MCP Tool.</p>
                <p>2. Dentro do flow, use HTTP para chamar o dispatcher MCP do CRM.</p>
                <p>3. Retorne dados com "Reply to MCP Client".</p>
                <p class="text-xs text-slate-500">Sempre envie token e company_id.</p>
            </div>
            <p class="text-xs text-slate-500 mt-3">Endpoints MCP do CRM:</p>
            <ul class="text-xs text-slate-600 list-disc pl-4 space-y-1">
                <li>GET <?= sanitize($apiBase) ?>/mcp-tools.php</li>
                <li>POST <?= sanitize($apiBase) ?>/mcp-call.php</li>
            </ul>
            <p class="text-xs text-slate-500 mt-2">Exemplo (POST mcp-call):</p>
            <pre class="text-[11px] bg-slate-900 text-slate-100 rounded p-3 overflow-x-auto">{
  "token": "<?= sanitize(API_TOKEN_IA) ?>",
  "tool": "client_search",
  "input": {
    "company_id": <?= $companyId ?>,
    "phone": "5511999999999"
  }
}</pre>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', () => {
        navigator.clipboard.writeText(btn.getAttribute('data-copy'));
        btn.textContent = 'Copiado!';
        setTimeout(() => btn.textContent = 'Copiar link', 1500);
    });
});
</script>
<?php include __DIR__ . '/views/partials/footer.php'; ?>
