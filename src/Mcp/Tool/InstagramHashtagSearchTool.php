<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramHashtagSearchTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_hashtag_search';
    }

    public function getDescription(): string
    {
        return 'Find content and creators by hashtag — the engine for discovery and competitor/niche research. '
            . 'Resolves a hashtag name to its id and (by default) returns the top public media for it; set media="recent" '
            . 'for the most recent posts, or media="none" to only resolve the id. Returned media includes permalinks and '
            . 'engagement counts you can use to find accounts to engage with and trends to ride. Note: Instagram limits '
            . 'you to ~30 unique hashtag lookups per 7 days per account, and only public business/creator media is returned.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'hashtag' => ['type' => 'string', 'description' => 'Hashtag to search (with or without leading #).'],
                'media' => [
                    'type' => 'string',
                    'description' => 'Which media to return: "top" (default), "recent", or "none".',
                    'enum' => ['top', 'recent', 'none'],
                ],
                'limit' => ['type' => 'integer', 'description' => 'Max media items to return (default 25).'],
            ],
            'required' => ['hashtag'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $hashtag = trim((string) ($arguments['hashtag'] ?? ''));
        if ($hashtag === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "hashtag" argument is required.']],
                'isError' => true,
            ];
        }

        $media = strtolower(trim((string) ($arguments['media'] ?? 'top')));
        if (!in_array($media, ['top', 'recent', 'none'], true)) {
            $media = 'top';
        }

        try {
            $result = $this->instagramService->hashtagSearch(
                accountKey: $arguments['account'] ?? null,
                hashtag: $hashtag,
                media: $media,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 25,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching Instagram hashtag: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
