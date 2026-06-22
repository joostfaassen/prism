<?php

namespace App\Mcp\Tool;

use App\SendGrid\SendGridService;

class SendGridGetGlobalStatsTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_get_global_stats';
    }

    public function getDescription(): string
    {
        return 'Get global SendGrid email statistics over a date range: requests, delivered, opens, unique_opens, clicks, unique_clicks, unsubscribes, bounces, spam_reports, blocks, invalid_emails and more. Optionally bucket the results by day, week or month. This is the main tool to answer "how many sends/opens/clicks/unsubscribes did we have in period X".';
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
                'aggregated_by' => [
                    'type' => 'string',
                    'description' => 'How to group the statistics over time. Omit for a single total over the range.',
                    'enum' => ['day', 'week', 'month'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of result buckets to return.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Point in the list to begin retrieving results.',
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
            $stats = $this->sendGridService->getGlobalStats(
                accountKey: $arguments['account'] ?? null,
                startDate: $startDate,
                endDate: $arguments['end_date'] ?? null,
                aggregatedBy: $arguments['aggregated_by'] ?? null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : null,
                offset: isset($arguments['offset']) ? (int) $arguments['offset'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'start_date' => $startDate,
                    'end_date' => $arguments['end_date'] ?? null,
                    'aggregated_by' => $arguments['aggregated_by'] ?? null,
                    'stats' => $stats,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching SendGrid global stats: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
