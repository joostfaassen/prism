<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramGetMediaTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_get_media';
    }

    public function getDescription(): string
    {
        return 'Get a single Instagram media object by its id, including carousel children. Returns caption, '
            . 'media type, URLs, permalink, timestamp and like/comment counts. Pass a custom fields list to '
            . 'fetch other Graph API fields.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'media_id' => ['type' => 'string', 'description' => 'The media object id (from instagram_list_media).'],
                'fields' => ['type' => 'string', 'description' => 'Optional comma-separated Graph API fields override.'],
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
            $result = $this->instagramService->getMedia(
                accountKey: $arguments['account'] ?? null,
                mediaId: $mediaId,
                fields: isset($arguments['fields']) ? (string) $arguments['fields'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Instagram media: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
