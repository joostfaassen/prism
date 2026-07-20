<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured TMDb (The Movie Database) accounts. Returns account keys, labels, and default language. Use the account key in other TMDb tools; if only one account is configured, the account argument can be omitted.';
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
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->tmdbService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing TMDb accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
