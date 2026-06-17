<?php

namespace App\Mcp\Tool;

use App\Matomo\MatomoService;

class MatomoListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly MatomoService $matomoService,
    ) {
    }

    public function getName(): string
    {
        return 'matomo_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Matomo analytics accounts. Returns account keys, labels, and any default site. Use the account key in other Matomo tools to choose which instance to query. If only one account is configured, the account argument can be omitted elsewhere.';
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
        return 'matomo';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->matomoService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Matomo accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
