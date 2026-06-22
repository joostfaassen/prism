<?php

namespace App\Mcp\Tool;

use App\SendGrid\SendGridService;

class SendGridGetCategoryStatsSumsTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_get_category_stats_sums';
    }

    public function getDescription(): string
    {
        return 'Get summed SendGrid email statistics per category over a date range, ranked by a chosen metric. Best tool to answer "which categories/templates generated the most clicks (or opens, etc.)" because it sorts categories by a metric and does not require naming them up front. Categories are commonly used to tag emails by template or campaign.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'SendGrid account key. Optional if only one account is configured.',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Start date of the statistics, format YYYY-MM-DD. Required.',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'End date of the statistics, format YYYY-MM-DD. Defaults to today.',
                ],
                'sort_by_metric' => [
                    'type' => 'string',
                    'description' => 'Single metric to sort the categories by, e.g. "clicks", "unique_clicks", "opens", "delivered", "unsubscribes". Defaults to "delivered".',
                ],
                'sort_by_direction' => [
                    'type' => 'string',
                    'description' => 'Sort direction. Defaults to "desc" (highest first).',
                    'enum' => ['desc', 'asc'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of categories to return. Defaults to 5 on the SendGrid side.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Point in the list to begin retrieving results.',
                ],
                'aggregated_by' => [
                    'type' => 'string',
                    'description' => 'How to group the statistics over time. Omit for a single total over the range.',
                    'enum' => ['day', 'week', 'month'],
                ],
            ],
            'required' => ['start_date'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'sendgrid';
    }

    public function execute(array $arguments): array
    {
        $startDate = trim((string) ($arguments['start_date'] ?? ''));

        if ($startDate === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "start_date" argument is required, format YYYY-MM-DD.']],
                'isError' => true,
            ];
        }

        try {
            $stats = $this->sendGridService->getCategoryStatsSums(
                accountKey: $arguments['account'] ?? null,
                startDate: $startDate,
                endDate: $arguments['end_date'] ?? null,
                sortByMetric: $arguments['sort_by_metric'] ?? null,
                sortByDirection: $arguments['sort_by_direction'] ?? null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : null,
                offset: isset($arguments['offset']) ? (int) $arguments['offset'] : null,
                aggregatedBy: $arguments['aggregated_by'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'start_date' => $startDate,
                    'end_date' => $arguments['end_date'] ?? null,
                    'sort_by_metric' => $arguments['sort_by_metric'] ?? 'delivered',
                    'stats' => $stats,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching SendGrid category stats sums: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
