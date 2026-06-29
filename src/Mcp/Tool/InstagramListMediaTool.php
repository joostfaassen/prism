<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramListMediaTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_list_media';
    }

    public function getDescription(): string
    {
        return 'List the account\'s published media (posts, reels, carousels) newest first, with like and comment '
            . 'counts. Returns a "data" array plus "paging" cursors; pass paging.cursors.after as the "after" '
            . 'argument to fetch the next page. Use a custom fields list to fetch additional Graph API media fields.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'fields' => ['type' => 'string', 'description' => 'Optional comma-separated Graph API media fields override.'],
                'limit' => ['type' => 'integer', 'description' => 'Max items per page (default 25).'],
                'after' => ['type' => 'string', 'description' => 'Pagination cursor (paging.cursors.after from a previous call).'],
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
            $result = $this->instagramService->listMedia(
                accountKey: $arguments['account'] ?? null,
                fields: isset($arguments['fields']) ? (string) $arguments['fields'] : null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 25,
                after: isset($arguments['after']) ? (string) $arguments['after'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Instagram media: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
