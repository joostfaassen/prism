<?php

namespace App\Mcp\Tool;

use App\N8n\N8nService;

class N8nGetExecutionTool implements ToolInterface
{
    public function __construct(
        private readonly N8nService $n8nService,
    ) {
    }

    public function getName(): string
    {
        return 'n8n_get_execution';
    }

    public function getDescription(): string
    {
        return 'Get a single n8n execution (run) by id. Returns metadata by default (status, mode, timestamps). Set include_data=true to include the full run data with node inputs/outputs, which can be very large. Use n8n_list_executions to discover execution ids.';
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
                'id' => [
                    'type' => 'string',
                    'description' => 'Execution id (from n8n_list_executions).',
                ],
                'include_data' => [
                    'type' => 'boolean',
                    'description' => 'Include full run data (node inputs/outputs). Defaults to false. Can be very large.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'n8n';
    }

    public function execute(array $arguments): array
    {
        $id = trim((string) ($arguments['id'] ?? ''));

        if ($id === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "id" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $execution = $this->n8nService->getExecution(
                accountKey: $arguments['account'] ?? null,
                id: $id,
                includeData: isset($arguments['include_data']) ? (bool) $arguments['include_data'] : false,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $execution,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting n8n execution: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
