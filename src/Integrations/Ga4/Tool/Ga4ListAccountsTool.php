<?php

namespace App\Integrations\Ga4\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Ga4\Ga4Service;

class Ga4ListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly Ga4Service $ga4Service,
    ) {
    }

    public function getName(): string
    {
        return 'ga4_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Google Analytics 4 (GA4) accounts. Returns account keys, labels, and any default property id. Use the account key in other GA4 tools to choose which property to query. If only one account is configured, the account argument can be omitted elsewhere.';
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
        return 'ga4';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->ga4Service->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing GA4 accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
