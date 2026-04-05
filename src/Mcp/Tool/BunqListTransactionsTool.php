<?php

namespace App\Mcp\Tool;

use App\Bunq\BunqService;

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
        return 'List transactions across one or more bunq bank accounts. Supports date range filtering. '
            . 'Use account key (e.g. "jf-personal"), comma-separated keys (e.g. "jf-personal,joost-lindsey-shared"), '
            . 'or "*" for all configured accounts.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'accounts' => [
                    'type' => 'string',
                    'description' => 'Account key, comma-separated keys, or "*" for all accounts',
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
                    'description' => 'Max transactions per account. Default: 50, max: 500',
                ],
            ],
            'required' => ['accounts'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'bunq';
    }

    public function execute(array $arguments): array
    {
        $accounts = $arguments['accounts'] ?? '';

        if ($accounts === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "accounts" is required. Use an account key, comma-separated keys, or "*" for all.']],
                'isError' => true,
            ];
        }

        $limit = (int) ($arguments['limit'] ?? 50);
        $limit = max(1, min($limit, 500));

        try {
            $result = $this->bunqService->listTransactions(
                accountsParam: $accounts,
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
                'accounts' => $result,
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
