<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Instagram (Business/Creator) accounts. Returns each account key, label, '
            . 'username, Instagram user id, whether credentials are present, whether the long-lived token can '
            . 'be auto-refreshed, and how many days until the token expires. Use the account key in other '
            . 'Instagram tools. If only one account is configured, the account argument can be omitted elsewhere.';
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
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->instagramService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Instagram accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
