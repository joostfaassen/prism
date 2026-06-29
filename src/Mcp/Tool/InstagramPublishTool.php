<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramPublishTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_publish';
    }

    public function getDescription(): string
    {
        return 'Publish content to Instagram: a single image, video, reel, story, or a multi-item carousel. '
            . 'Provide media_type and the matching media URL(s) — image_url / video_url must be publicly reachable HTTPS '
            . 'URLs that Instagram can download (host the asset yourself, e.g. generated via Canva). For videos/reels '
            . 'the tool creates the container, waits for processing to finish, then publishes. '
            . 'Set publish=false to only create a ready container (returns creation_id) for scheduled/deferred publishing; '
            . 'later call again with creation_id to publish it. Accounts are limited to 100 published posts per 24h '
            . '(see instagram_get_publishing_limit). Captions support hashtags and @mentions in the text.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'media_type' => [
                    'type' => 'string',
                    'description' => 'Type of post: IMAGE, VIDEO, REELS, STORIES, or CAROUSEL.',
                    'enum' => ['IMAGE', 'VIDEO', 'REELS', 'STORIES', 'CAROUSEL'],
                ],
                'image_url' => ['type' => 'string', 'description' => 'Public HTTPS URL of the image (for IMAGE or image STORIES).'],
                'video_url' => ['type' => 'string', 'description' => 'Public HTTPS URL of the video (for VIDEO, REELS, or video STORIES).'],
                'caption' => ['type' => 'string', 'description' => 'Caption text; may include hashtags and @mentions. Not used for STORIES.'],
                'children' => [
                    'type' => 'array',
                    'description' => 'For CAROUSEL only: 2-10 items, each {"image_url": "..."} or {"media_type":"VIDEO","video_url":"..."}.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'media_type' => ['type' => 'string', 'enum' => ['IMAGE', 'VIDEO']],
                            'image_url' => ['type' => 'string'],
                            'video_url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'cover_url' => ['type' => 'string', 'description' => 'REELS/VIDEO: public HTTPS URL of a cover image.'],
                'thumb_offset' => ['type' => 'integer', 'description' => 'REELS/VIDEO: milliseconds into the video to use as the thumbnail.'],
                'share_to_feed' => ['type' => 'boolean', 'description' => 'REELS: also show the reel in the main feed (default true on Instagram).'],
                'alt_text' => ['type' => 'string', 'description' => 'IMAGE: accessibility alt text.'],
                'location_id' => ['type' => 'string', 'description' => 'Optional Facebook Page location id to tag.'],
                'user_tags' => ['description' => 'Optional array of user tags. Images: [{"username":"x","x":0.5,"y":0.5}]. Reels/video accept usernames.'],
                'collaborators' => ['description' => 'Optional array of usernames to invite as collaborators.'],
                'publish' => ['type' => 'boolean', 'description' => 'Publish immediately (default true). Set false to only build a container.'],
                'creation_id' => ['type' => 'string', 'description' => 'Publish a previously created container instead of building a new one.'],
                'max_wait_seconds' => ['type' => 'integer', 'description' => 'How long to wait for video/reel processing before giving up (default 90).'],
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
        $hasCreationId = trim((string) ($arguments['creation_id'] ?? '')) !== '';
        if (!$hasCreationId && trim((string) ($arguments['media_type'] ?? '')) === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Provide a "media_type" (IMAGE, VIDEO, REELS, STORIES, CAROUSEL) or a "creation_id" to publish.']],
                'isError' => true,
            ];
        }

        $opts = array_intersect_key($arguments, array_flip([
            'media_type', 'image_url', 'video_url', 'caption', 'children', 'cover_url', 'thumb_offset',
            'share_to_feed', 'alt_text', 'location_id', 'user_tags', 'collaborators', 'publish',
            'creation_id', 'max_wait_seconds',
        ]));

        try {
            $result = $this->instagramService->publish($arguments['account'] ?? null, $opts);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error publishing to Instagram: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
