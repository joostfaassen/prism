<?php

namespace App\Mcp\Tool;

use App\N8n\N8nService;

class N8nListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly N8nService $n8nService,
    ) {
    }

    public function getName(): string
    {
        return 'n8n_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured n8n automation accounts (instances). Returns account keys, labels and base URLs. Use the account key in other n8n tools to choose which instance to query. If only one account is configured, the account argument can be omitted elsewhere.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
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
            $accounts = $this->n8nService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing n8n accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
