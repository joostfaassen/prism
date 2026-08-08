<?php

namespace App\Integrations\Bunq\Tool;

use App\Integrations\Bunq\BunqService;
use App\Mcp\Tool\ToolInterface;

/**
 * Prepare a bunq draft payment for later confirmation in the bunq app.
 *
 * Money is NOT sent until the user accepts the draft in the app. Prefer this over
 * a direct payment when an agent should stage a transfer for human approval.
 */
class BunqCreateDraftPaymentTool implements ToolInterface
{
    public function __construct(
        private readonly BunqService $bunqService,
    ) {
    }

    public function getName(): string
    {
        return 'bunq_create_draft_payment';
    }

    public function getDescription(): string
    {
        return 'Create a bunq draft payment that appears in the bunq app for confirmation. '
            . 'Does NOT send money — the user must accept the draft in the bunq app before '
            . 'the transfer executes. Use for IBAN, email (bunq.to), or phone-number recipients. '
            . 'For IBAN payments, counterparty_name is required. '
            . 'Call bunq_list_profiles with discover=true first if you need monetary_account_id.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Bunq profile key to pay from (see bunq_list_profiles).',
                ],
                'amount' => [
                    'type' => 'string',
                    'description' => 'Amount to transfer, as a decimal string (e.g. "12.50"). Must be > 0.',
                ],
                'currency' => [
                    'type' => 'string',
                    'description' => 'ISO 4217 currency code. Default: EUR.',
                    'default' => 'EUR',
                ],
                'counterparty_type' => [
                    'type' => 'string',
                    'enum' => ['IBAN', 'EMAIL', 'PHONE_NUMBER'],
                    'description' => 'How to identify the recipient. IBAN for bank accounts; '
                        . 'EMAIL or PHONE_NUMBER for bunq.to-style payments.',
                ],
                'counterparty_value' => [
                    'type' => 'string',
                    'description' => 'Recipient identifier: IBAN (spaces allowed), email, '
                        . 'or phone in E.123 form without spaces (e.g. +31612345678).',
                ],
                'counterparty_name' => [
                    'type' => 'string',
                    'description' => 'Recipient display name. Required for IBAN; optional otherwise.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Payment description / remittance info. '
                        . 'Max 140 characters for external IBAN, 9000 for bunq-to-bunq. '
                        . 'May be an empty string.',
                ],
                'monetary_account_id' => [
                    'type' => 'integer',
                    'description' => 'Monetary account to pay from. Optional when the profile '
                        . 'has monetary_account_id configured or only one active account.',
                ],
                'merchant_reference' => [
                    'type' => 'string',
                    'description' => 'Optional merchant/reference string attached to the payment.',
                ],
            ],
            'required' => ['profile', 'amount', 'counterparty_type', 'counterparty_value', 'description'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'bunq';
    }

    public function execute(array $arguments): array
    {
        $profile = trim((string) ($arguments['profile'] ?? ''));
        $amount = (string) ($arguments['amount'] ?? '');
        $counterpartyType = (string) ($arguments['counterparty_type'] ?? '');
        $counterpartyValue = (string) ($arguments['counterparty_value'] ?? '');

        if ($profile === '') {
            return $this->error('Parameter "profile" is required.');
        }
        if ($amount === '') {
            return $this->error('Parameter "amount" is required (e.g. "12.50").');
        }
        if ($counterpartyType === '') {
            return $this->error('Parameter "counterparty_type" is required: IBAN, EMAIL, or PHONE_NUMBER.');
        }
        if ($counterpartyValue === '') {
            return $this->error('Parameter "counterparty_value" is required.');
        }
        if (!array_key_exists('description', $arguments)) {
            return $this->error('Parameter "description" is required (may be an empty string).');
        }

        try {
            $result = $this->bunqService->createDraftPayment(
                profileKey: $profile,
                amount: $amount,
                currency: (string) ($arguments['currency'] ?? 'EUR'),
                counterpartyType: $counterpartyType,
                counterpartyValue: $counterpartyValue,
                description: (string) $arguments['description'],
                counterpartyName: isset($arguments['counterparty_name'])
                    ? (string) $arguments['counterparty_name']
                    : null,
                monetaryAccountId: isset($arguments['monetary_account_id'])
                    ? (int) $arguments['monetary_account_id']
                    : null,
                merchantReference: isset($arguments['merchant_reference'])
                    ? (string) $arguments['merchant_reference']
                    : null,
            );

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
                ]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error creating draft payment: ' . $e->getMessage());
        }
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: true}
     */
    private function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
