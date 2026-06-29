<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramGetMediaInsightsTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_get_media_insights';
    }

    public function getDescription(): string
    {
        return 'Get per-media insights to evaluate how a specific post/reel performed. Supply comma-separated '
            . 'metrics. Common metrics: reach, views, likes, comments, shares, saved, total_interactions, '
            . 'profile_visits, follows, profile_activity. Reels also support: ig_reels_avg_watch_time, '
            . 'ig_reels_video_view_total_time, clips_replays_count. Some metrics support a breakdown.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'media_id' => ['type' => 'string', 'description' => 'The media object id (from instagram_list_media).'],
                'metric' => ['type' => 'string', 'description' => 'Comma-separated metric names, e.g. "reach,likes,comments,saved,shares".'],
                'breakdown' => ['type' => 'string', 'description' => 'Optional breakdown dimension (e.g. "action_type" for profile_activity).'],
            ],
            'required' => ['media_id', 'metric'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $mediaId = trim((string) ($arguments['media_id'] ?? ''));
        $metric = trim((string) ($arguments['metric'] ?? ''));
        if ($mediaId === '' || $metric === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Both "media_id" and "metric" are required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->instagramService->getMediaInsights(
                accountKey: $arguments['account'] ?? null,
                mediaId: $mediaId,
                metric: $metric,
                breakdown: isset($arguments['breakdown']) ? (string) $arguments['breakdown'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Instagram media insights: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
