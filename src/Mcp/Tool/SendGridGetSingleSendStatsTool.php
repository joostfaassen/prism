<?php

namespace App\Mcp\Tool;

use App\SendGrid\SendGridService;

class SendGridGetSingleSendStatsTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_get_single_send_stats';
    }

    public function getDescription(): string
    {
        return 'Get SendGrid Marketing Campaigns Single Send statistics (delivered, opens, unique_opens, clicks, unique_clicks, bounces, spam_reports, unsubscribes). Without single_send_id it returns stats for all Single Sends so you can compare campaigns; with single_send_id it returns that one campaign in detail. Use sendgrid_list_single_sends to discover ids and names.';
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
                'single_send_id' => [
                    'type' => 'string',
                    'description' => 'Optional Single Send id (UUID). Omit to get stats for all Single Sends.',
                ],
                'aggregated_by' => [
                    'type' => 'string',
                    'description' => 'How to time-slice the stats. Defaults to a single total.',
                    'enum' => ['total', 'day'],
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Start date constraint, format YYYY-MM-DD.',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'End date constraint, format YYYY-MM-DD.',
                ],
                'page_size' => [
                    'type' => 'integer',
                    'description' => 'Number of results to return per page.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'sendgrid';
    }

    public function execute(array $arguments): array
    {
        try {
            $stats = $this->sendGridService->getSingleSendStats(
                accountKey: $arguments['account'] ?? null,
                singleSendId: $arguments['single_send_id'] ?? null,
                aggregatedBy: $arguments['aggregated_by'] ?? null,
                startDate: $arguments['start_date'] ?? null,
                endDate: $arguments['end_date'] ?? null,
                pageSize: isset($arguments['page_size']) ? (int) $arguments['page_size'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'single_send_id' => $arguments['single_send_id'] ?? null,
                    'stats' => $stats,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching SendGrid single send stats: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
