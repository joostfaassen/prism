<?php

namespace App\Mcp\Tool\Apify;

use App\Apify\ApifyService;
use App\Mcp\Tool\ToolInterface;

class ApifyListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly ApifyService $apifyService,
    ) {
    }

    public function getName(): string
    {
        return 'apify_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List the configured Apify accounts available on this server. Returns each account key, label and API base URL. Use the key as the "account" argument for other Apify tools.';
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
        return 'apify';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->apifyService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Apify accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
