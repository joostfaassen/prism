<?php

namespace App\Mcp\Tool;

use App\N8n\N8nService;

class N8nListWorkflowsTool implements ToolInterface
{
    public function __construct(
        private readonly N8nService $n8nService,
    ) {
    }

    public function getName(): string
    {
        return 'n8n_list_workflows';
    }

    public function getDescription(): string
    {
        return 'List n8n workflows (flows) on an instance. Returns metadata only: id, name, active state, tags, node count and timestamps. Supports filtering by active state, name and tags, plus cursor-based pagination. Use n8n_get_workflow to read or download the full definition of a single flow.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'n8n account key (from n8n_list_accounts). Optional if only one account is configured.',
                ],
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Filter by active state. Omit to return both active and inactive workflows.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Filter by exact workflow name.',
                ],
                'tags' => [
                    'type' => 'string',
                    'description' => 'Comma-separated tag names to filter by.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of workflows to return (default 50, max 250).',
                ],
                'cursor' => [
                    'type' => 'string',
                    'description' => 'Pagination cursor from a previous response (nextCursor).',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'n8n';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->n8nService->listWorkflows(
                accountKey: $arguments['account'] ?? null,
                active: isset($arguments['active']) ? (bool) $arguments['active'] : null,
                name: $arguments['name'] ?? null,
                tags: $arguments['tags'] ?? null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 50,
                cursor: $arguments['cursor'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($result['data']),
                    'workflows' => $result['data'],
                    'nextCursor' => $result['nextCursor'],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing n8n workflows: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
