<?php

namespace App\Mcp\Tool;

use App\Bunq\BunqService;

class BunqGetTransactionTool implements ToolInterface
{
    public function __construct(
        private readonly BunqService $bunqService,
    ) {
    }

    public function getName(): string
    {
        return 'bunq_get_transaction';
    }

    public function getDescription(): string
    {
        return 'Get full details of a specific bunq transaction by payment ID. '
            . 'Returns amount, counterparty, balance after mutation, geolocation, and more.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payment_id' => [
                    'type' => 'integer',
                    'description' => 'The bunq payment ID',
                ],
                'monetary_account_id' => [
                    'type' => 'integer',
                    'description' => 'The monetary account ID. Optional — omit to use the primary account.',
                ],
            ],
            'required' => ['payment_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'bunq';
    }

    public function execute(array $arguments): array
    {
        $paymentId = $arguments['payment_id'] ?? null;

        if ($paymentId === null) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "payment_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->bunqService->getTransaction(
                paymentId: (int) $paymentId,
                monetaryAccountId: isset($arguments['monetary_account_id']) ? (int) $arguments['monetary_account_id'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching transaction: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
