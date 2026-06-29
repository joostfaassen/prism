<?php

namespace App\Mcp\Tool;

use App\N8n\N8nService;

class N8nGetWorkflowTool implements ToolInterface
{
    public function __construct(
        private readonly N8nService $n8nService,
    ) {
    }

    public function getName(): string
    {
        return 'n8n_get_workflow';
    }

    public function getDescription(): string
    {
        return 'Get (download) a single n8n workflow by id, including its full definition: nodes, connections and settings. The returned "definition" is the complete workflow JSON that can be re-imported into n8n. Use n8n_list_workflows to discover workflow ids.';
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
                    'description' => 'Workflow id (from n8n_list_workflows).',
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
            $workflow = $this->n8nService->getWorkflow(
                accountKey: $arguments['account'] ?? null,
                id: $id,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $workflow,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting n8n workflow: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
