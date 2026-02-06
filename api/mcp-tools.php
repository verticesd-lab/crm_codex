<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/utils.php';

checkApiToken();

$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$apiBase = $proto . $host . '/api';

$tools = [
    [
        'name' => 'client_search',
        'description' => 'Busca cliente por phone/instagram e retorna pedidos e interacoes recentes.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'company_id' => ['type' => 'integer'],
                'phone' => ['type' => 'string'],
                'telefone' => ['type' => 'string'],
                'whatsapp' => ['type' => 'string'],
                'instagram' => ['type' => 'string'],
                'instagram_username' => ['type' => 'string'],
            ],
            'required' => ['company_id'],
        ],
    ],
    [
        'name' => 'client_create_or_update',
        'description' => 'Cria ou atualiza cliente por telefone/instagram.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'company_id' => ['type' => 'integer'],
                'nome' => ['type' => 'string'],
                'telefone' => ['type' => 'string'],
                'whatsapp' => ['type' => 'string'],
                'instagram' => ['type' => 'string'],
                'instagram_username' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'tags' => ['type' => 'string'],
            ],
            'required' => ['company_id', 'nome'],
        ],
    ],
    [
        'name' => 'interaction_create',
        'description' => 'Registra uma interacao para um cliente.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'company_id' => ['type' => 'integer'],
                'client_id' => ['type' => 'integer'],
                'canal' => ['type' => 'string'],
                'origem' => ['type' => 'string'],
                'titulo' => ['type' => 'string'],
                'resumo' => ['type' => 'string'],
                'atendente' => ['type' => 'string'],
            ],
            'required' => ['company_id', 'client_id', 'titulo', 'resumo'],
        ],
    ],
    [
        'name' => 'order_create_from_chat',
        'description' => 'Cria pedido a partir de itens informados.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'company_id' => ['type' => 'integer'],
                'client_id' => ['type' => 'integer'],
                'origem' => ['type' => 'string'],
                'canal' => ['type' => 'string'],
                'itens' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'integer'],
                            'quantidade' => ['type' => 'integer'],
                            'quantity' => ['type' => 'integer'],
                        ],
                        'required' => ['product_id'],
                    ],
                ],
            ],
            'required' => ['company_id', 'client_id', 'itens'],
        ],
    ],
    [
        'name' => 'products_list',
        'description' => 'Lista produtos ativos com filtros opcionais.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'company_id' => ['type' => 'integer'],
                'categoria' => ['type' => 'string'],
                'busca' => ['type' => 'string'],
                'search' => ['type' => 'string'],
            ],
            'required' => ['company_id'],
        ],
    ],
    [
        'name' => 'client_timeline',
        'description' => 'Retorna timeline do cliente com interacoes e pedidos.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'company_id' => ['type' => 'integer'],
                'client_id' => ['type' => 'integer'],
            ],
            'required' => ['company_id', 'client_id'],
        ],
    ],
    [
        'name' => 'lock_ai',
        'description' => 'Aplica bloqueio temporario da IA por telefone na empresa da sessao.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'phone' => ['type' => 'string'],
                'minutes' => ['type' => 'integer'],
            ],
            'required' => ['phone', 'minutes'],
        ],
    ],
];

apiJsonResponse(true, [
    'name' => 'crm_codex_mcp',
    'version' => '1.0.0',
    'description' => 'Ferramentas MCP do CRM Codex para uso via Activepieces.',
    'api_base' => $apiBase,
    'tools' => $tools,
]);
