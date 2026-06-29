<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramBusinessDiscoveryTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_business_discovery';
    }

    public function getDescription(): string
    {
        return 'Look up ANOTHER public Instagram business/creator account by username — perfect for competitor and '
            . 'influencer research. Returns their follower count, follows count, media count, bio, website and recent '
            . 'posts (with like/comment/view counts) in a single call. Combine with instagram_hashtag_search to discover '
            . 'usernames, then profile them here. Only public professional accounts are returned (no private/personal accounts).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Your Instagram account key used to perform the lookup. Optional if only one is configured.'],
                'username' => ['type' => 'string', 'description' => 'The target public business/creator username (with or without leading @).'],
                'media_limit' => ['type' => 'integer', 'description' => 'How many of the target\'s recent media to include (default 12).'],
                'fields' => ['type' => 'string', 'description' => 'Optional override of the nested business_discovery fields list.'],
            ],
            'required' => ['username'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $username = trim((string) ($arguments['username'] ?? ''));
        if ($username === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "username" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->instagramService->businessDiscovery(
                accountKey: $arguments['account'] ?? null,
                username: $username,
                fields: isset($arguments['fields']) ? (string) $arguments['fields'] : null,
                mediaLimit: isset($arguments['media_limit']) ? (int) $arguments['media_limit'] : 12,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Instagram business discovery: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
