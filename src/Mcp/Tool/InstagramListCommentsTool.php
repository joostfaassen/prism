<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramListCommentsTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_list_comments';
    }

    public function getDescription(): string
    {
        return 'List comments on one of your media objects, including threaded replies, like counts and whether '
            . 'each comment is hidden. Use this to triage engagement and find comments worth replying to. Returns '
            . '"data" plus "paging" cursors for pagination.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'media_id' => ['type' => 'string', 'description' => 'The media object id whose comments to list.'],
                'limit' => ['type' => 'integer', 'description' => 'Max comments per page (default 25).'],
                'after' => ['type' => 'string', 'description' => 'Pagination cursor from a previous call.'],
            ],
            'required' => ['media_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $mediaId = trim((string) ($arguments['media_id'] ?? ''));
        if ($mediaId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "media_id" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->instagramService->listComments(
                accountKey: $arguments['account'] ?? null,
                mediaId: $mediaId,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 25,
                after: isset($arguments['after']) ? (string) $arguments['after'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Instagram comments: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
