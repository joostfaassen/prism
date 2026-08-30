<?php

namespace App\Integrations\Bunq\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Bunq\BunqService;

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
        return 'Get full details of a bunq transaction: amount, counterparty, geolocation, '
            . 'text notes, and attachment references. '
            . 'has_attachments / attachment_ids tell you if files exist; '
            . 'download bytes only with bunq_get_transaction_attachment.';
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
                    'description' => 'The monetary account ID. Optional — omit to use the primary profile.',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'bunq profile key. Optional if only one profile is configured.',
                ],
            ],
            'required' => ['payment_id'],
        ];
    }

    public function getProfileType(): ?string
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
                profileKey: isset($arguments['profile']) && $arguments['profile'] !== ''
                    ? (string) $arguments['profile']
                    : null,
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
