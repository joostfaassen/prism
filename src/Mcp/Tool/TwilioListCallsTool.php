<?php

namespace App\Mcp\Tool;

use App\Twilio\TwilioService;

class TwilioListCallsTool implements ToolInterface
{
    public function __construct(
        private readonly TwilioService $twilioService,
    ) {
    }

    public function getName(): string
    {
        return 'twilio_list_calls';
    }

    public function getDescription(): string
    {
        return 'List calls for a Twilio account. Supports filtering by status, phone numbers, and date range. Returns call metadata including SID, direction, duration, and timestamps.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Twilio account key from configuration',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by call status: queued, ringing, in-progress, completed, busy, failed, no-answer, canceled',
                    'enum' => ['queued', 'ringing', 'in-progress', 'completed', 'busy', 'failed', 'no-answer', 'canceled'],
                ],
                'from' => [
                    'type' => 'string',
                    'description' => 'Filter by caller phone number (E.164 format)',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'Filter by called phone number (E.164 format)',
                ],
                'start_time_after' => [
                    'type' => 'string',
                    'description' => 'Only include calls started after this date (YYYY-MM-DD)',
                ],
                'start_time_before' => [
                    'type' => 'string',
                    'description' => 'Only include calls started before this date (YYYY-MM-DD)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max number of calls to return (default 20, max 1000)',
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'twilio';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        if ($accountKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Missing required parameter: account']],
                'isError' => true,
            ];
        }

        try {
            $filters = array_filter([
                'status' => $arguments['status'] ?? null,
                'from' => $arguments['from'] ?? null,
                'to' => $arguments['to'] ?? null,
                'start_time_after' => $arguments['start_time_after'] ?? null,
                'start_time_before' => $arguments['start_time_before'] ?? null,
                'limit' => $arguments['limit'] ?? null,
            ], fn($v) => $v !== null);

            $result = $this->twilioService->listCalls($accountKey, $filters);

            $calls = array_map(fn(array $call) => [
                'sid' => $call['sid'],
                'from' => $call['from_formatted'] ?? $call['from'],
                'to' => $call['to_formatted'] ?? $call['to'],
                'direction' => $call['direction'],
                'status' => $call['status'],
                'duration' => $call['duration'],
                'start_time' => $call['start_time'],
                'end_time' => $call['end_time'],
                'price' => $call['price'],
                'price_unit' => $call['price_unit'],
            ], $result['calls']);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($calls),
                    'calls' => $calls,
                    'page_info' => $result['page_info'],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing calls: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
