<?php

namespace App\Mcp\Tool;

use App\N8n\N8nService;

class N8nListExecutionsTool implements ToolInterface
{
    public function __construct(
        private readonly N8nService $n8nService,
    ) {
    }

    public function getName(): string
    {
        return 'n8n_list_executions';
    }

    public function getDescription(): string
    {
        return 'List n8n executions (runs). Returns metadata by default: id, workflow id, status, mode and timestamps. Filter by workflow id and/or status (success, error, waiting, ...), and paginate with a cursor. Set include_data=true to include the full run data per execution (can be very large).';
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
                'workflow_id' => [
                    'type' => 'string',
                    'description' => 'Filter executions by workflow id (from n8n_list_workflows).',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by execution status.',
                    'enum' => ['canceled', 'crashed', 'error', 'new', 'running', 'success', 'unknown', 'waiting'],
                ],
                'include_data' => [
                    'type' => 'boolean',
                    'description' => 'Include full run data (node inputs/outputs) for each execution. Defaults to false. Can be very large.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of executions to return (default 25, max 250).',
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
            $result = $this->n8nService->listExecutions(
                accountKey: $arguments['account'] ?? null,
                workflowId: $arguments['workflow_id'] ?? null,
                status: $arguments['status'] ?? null,
                includeData: isset($arguments['include_data']) ? (bool) $arguments['include_data'] : false,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 25,
                cursor: $arguments['cursor'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($result['data']),
                    'executions' => $result['data'],
                    'nextCursor' => $result['nextCursor'],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing n8n executions: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
