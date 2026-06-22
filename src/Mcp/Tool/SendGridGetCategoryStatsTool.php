<?php

namespace App\Mcp\Tool;

use App\SendGrid\SendGridService;

class SendGridGetCategoryStatsTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_get_category_stats';
    }

    public function getDescription(): string
    {
        return 'Get SendGrid email statistics broken down by category over a date range. Categories are commonly used to tag emails by template, campaign or message type, so this reveals opens, clicks, unsubscribes etc. per category, optionally bucketed by day/week/month. You must name the categories to retrieve (up to 10). To rank categories by a metric instead, use sendgrid_get_category_stats_sums.';
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
                'categories' => [
                    'type' => 'array',
                    'description' => 'Category names to retrieve statistics for (up to 10). Required.',
                    'items' => ['type' => 'string'],
                ],
                'aggregated_by' => [
                    'type' => 'string',
                    'description' => 'How to group the statistics over time. Omit for a single total over the range.',
                    'enum' => ['day', 'week', 'month'],
                ],
            ],
            'required' => ['start_date', 'categories'],
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

        $categories = [];
        if (isset($arguments['categories']) && is_array($arguments['categories'])) {
            foreach ($arguments['categories'] as $category) {
                if (is_scalar($category) && (string) $category !== '') {
                    $categories[] = (string) $category;
                }
            }
        }

        if ($categories === []) {
            return [
                'content' => [['type' => 'text', 'text' => 'The "categories" argument is required and must contain at least one category name.']],
                'isError' => true,
            ];
        }

        try {
            $stats = $this->sendGridService->getCategoryStats(
                accountKey: $arguments['account'] ?? null,
                startDate: $startDate,
                categories: $categories,
                endDate: $arguments['end_date'] ?? null,
                aggregatedBy: $arguments['aggregated_by'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'start_date' => $startDate,
                    'end_date' => $arguments['end_date'] ?? null,
                    'categories' => $categories,
                    'aggregated_by' => $arguments['aggregated_by'] ?? null,
                    'stats' => $stats,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching SendGrid category stats: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
