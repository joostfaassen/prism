<?php

namespace App\Mcp\Tool;

use App\Loki\LokiService;

class LokiListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly LokiService $lokiService,
    ) {
    }

    public function getName(): string
    {
        return 'loki_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Loki (log aggregation) accounts. Returns account keys, labels, and base '
            . 'URLs. Use the account key in other Loki tools to choose which instance to query. '
            . 'If only one account is configured, the account argument can be omitted elsewhere.';
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
        return 'loki';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->lokiService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Loki accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
