<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramGetAccountTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_get_account';
    }

    public function getDescription(): string
    {
        return 'Get the authenticated Instagram account profile: username, name, biography, website, '
            . 'profile picture, followers_count, follows_count and media_count. This is your "page info" overview. '
            . 'Optionally pass a custom comma-separated Graph API fields list to fetch other public profile fields.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Instagram account key. Optional if only one account is configured.',
                ],
                'fields' => [
                    'type' => 'string',
                    'description' => 'Optional comma-separated Graph API fields override (e.g. "username,followers_count,media_count").',
                ],
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
            $result = $this->instagramService->getAccount(
                accountKey: $arguments['account'] ?? null,
                fields: isset($arguments['fields']) ? (string) $arguments['fields'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Instagram account: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
