<?php

namespace App\Integrations\Bunq\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Bunq\BunqService;

class BunqListTransactionsTool implements ToolInterface
{
    public function __construct(
        private readonly BunqService $bunqService,
    ) {
    }

    public function getName(): string
    {
        return 'bunq_list_transactions';
    }

    public function getDescription(): string
    {
        return 'List transactions across one or more bunq bank profiles. Supports date range filtering. '
            . 'Use profile key (e.g. "personal"), comma-separated keys (e.g. "personal,shared-household"), '
            . 'or "*" for all configured profiles. '
            . 'Summaries only — call bunq_get_transaction for notes and attachment references.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profiles' => [
                    'type' => 'string',
                    'description' => 'Profile key, comma-separated keys, or "*" for all profiles',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Start date (inclusive), ISO 8601 date format (YYYY-MM-DD). Omit for no lower bound.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'End date (inclusive), ISO 8601 date format (YYYY-MM-DD). Omit for no upper bound.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max transactions per profile. Default: 50, max: 500',
                ],
            ],
            'required' => ['profiles'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'bunq';
    }

    public function execute(array $arguments): array
    {
        $profiles = $arguments['profiles'] ?? '';

        if ($profiles === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "profiles" is required. Use a profile key, comma-separated keys, or "*" for all.']],
                'isError' => true,
            ];
        }

        $limit = (int) ($arguments['limit'] ?? 50);
        $limit = max(1, min($limit, 500));

        try {
            $result = $this->bunqService->listTransactions(
                profilesParam: $profiles,
                dateFrom: $arguments['date_from'] ?? null,
                dateTo: $arguments['date_to'] ?? null,
                limit: $limit,
            );

            $totalCount = 0;
            foreach ($result as $transactions) {
                $totalCount += count($transactions);
            }

            $output = [
                'total_transactions' => $totalCount,
                'profiles' => $result,
            ];

            return [
                'content' => [['type' => 'text', 'text' => json_encode($output, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing transactions: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
