<?php

namespace App\Mcp\Tool;

use App\Transip\TransipService;

class TransipListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured TransIP accounts. Returns account keys, labels, the TransIP login, and whether the account is read-only. Use the account key in other TransIP tools to choose which account to use. If only one account is configured, the account argument can be omitted elsewhere.';
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
        return 'transip';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->transipService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing TransIP accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
