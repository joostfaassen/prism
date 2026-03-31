<?php

namespace App\Mcp\Tool;

use App\Bunq\BunqService;

class BunqListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly BunqService $bunqService,
    ) {
    }

    public function getName(): string
    {
        return 'bunq_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List all configured bunq bank accounts. Returns account keys that can be used with other bunq tools. Optionally discovers monetary account IDs from the bunq API.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'discover' => [
                    'type' => 'boolean',
                    'description' => 'If true, also fetches monetary accounts from the bunq API to show IDs and balances. Default: false',
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        try {
            $configured = $this->bunqService->listAccounts();
            $result = ['configured_accounts' => $configured];

            if ($arguments['discover'] ?? false) {
                $result['bunq_monetary_accounts'] = $this->bunqService->listMonetaryAccounts();
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing bunq accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
