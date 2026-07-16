<?php

namespace App\Mcp\Tool;

use App\GitHub\GitHubService;

class GitHubListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly GitHubService $gitHubService,
    ) {
    }

    public function getName(): string
    {
        return 'github_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured GitHub accounts. Returns account keys, labels, base URL, and any default login. '
            . 'Use the account key in other GitHub tools to choose which token to use. '
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
        return 'github';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->gitHubService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing GitHub accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
