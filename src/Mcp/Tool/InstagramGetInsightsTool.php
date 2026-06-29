<?php

namespace App\Mcp\Tool;

use App\Instagram\InstagramService;

class InstagramGetInsightsTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_get_insights';
    }

    public function getDescription(): string
    {
        return 'Get account-level Instagram insights for growth and engagement analysis. Supply one or more '
            . 'metrics (comma-separated). Common metrics: reach, profile_views, accounts_engaged, total_interactions, '
            . 'likes, comments, shares, saves, replies, follows_and_unfollows, profile_links_taps, views, '
            . 'reached_audience_demographics, engaged_audience_demographics, follower_demographics. '
            . 'Newer metrics require metric_type="total_value"; demographic metrics also need a breakdown '
            . '(e.g. "age", "city", "country", "gender") and timeframe (e.g. "this_month", "last_14_days", "prev_month"). '
            . 'period is one of day, week, days_28, month, lifetime. Use since/until (unix seconds) to bound a range.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Instagram account key. Optional if only one is configured.'],
                'metric' => ['type' => 'string', 'description' => 'Comma-separated metric names, e.g. "reach,profile_views,total_interactions".'],
                'period' => ['type' => 'string', 'description' => 'Aggregation period: day, week, days_28, month, lifetime. Defaults to day.'],
                'metric_type' => ['type' => 'string', 'description' => 'Set to "total_value" for newer aggregated metrics (most engagement/demographic metrics).'],
                'breakdown' => ['type' => 'string', 'description' => 'Breakdown dimension for demographic/total_value metrics, e.g. "age", "city", "country", "gender", "follow_type", "media_product_type".'],
                'timeframe' => ['type' => 'string', 'description' => 'Required for demographics metrics, e.g. "last_14_days", "last_30_days", "this_month", "prev_month".'],
                'since' => ['type' => 'integer', 'description' => 'Optional range start as a unix timestamp (seconds).'],
                'until' => ['type' => 'integer', 'description' => 'Optional range end as a unix timestamp (seconds).'],
            ],
            'required' => ['metric'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        $metric = trim((string) ($arguments['metric'] ?? ''));
        if ($metric === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "metric" argument is required, e.g. "reach,profile_views".']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->instagramService->getInsights(
                accountKey: $arguments['account'] ?? null,
                metric: $metric,
                period: isset($arguments['period']) ? (string) $arguments['period'] : 'day',
                metricType: isset($arguments['metric_type']) ? (string) $arguments['metric_type'] : null,
                breakdown: isset($arguments['breakdown']) ? (string) $arguments['breakdown'] : null,
                timeframe: isset($arguments['timeframe']) ? (string) $arguments['timeframe'] : null,
                since: isset($arguments['since']) ? (int) $arguments['since'] : null,
                until: isset($arguments['until']) ? (int) $arguments['until'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Instagram insights: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
