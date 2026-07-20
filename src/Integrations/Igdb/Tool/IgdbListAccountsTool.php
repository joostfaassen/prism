<?php

namespace App\Integrations\Igdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Igdb\IgdbService;

class IgdbListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly IgdbService $igdbService,
    ) {
    }

    public function getName(): string
    {
        return 'igdb_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured IGDB (game database) accounts. Returns account keys and labels. Use the account key in other IGDB tools; if only one account is configured, the account argument can be omitted.';
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
        return 'igdb';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->igdbService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing IGDB accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
