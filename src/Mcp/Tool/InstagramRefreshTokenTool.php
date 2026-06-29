<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramRefreshTokenTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_refresh_token';
    }

    public function getDescription(): string
    {
        return 'Refresh the account\'s long-lived access token (extends it ~60 days) and persist it back to the Prism '
            . 'config so automations keep working. Requires app_id and app_secret to be configured for the account. '
            . 'Run periodically (e.g. weekly) well before the token expires.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
            ],
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
            $result = $this->instagramService->refreshToken($arguments['account'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'refreshed' => true,
                    'token_expires_at' => $result['token_expires_at'],
                    'expires_at_iso' => $result['token_expires_at'] > 0
                        ? date('c', $result['token_expires_at'])
                        : null,
                    'days_left' => $result['days_left'],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error refreshing Instagram token: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
