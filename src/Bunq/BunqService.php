<?php

namespace App\Bunq;

use bunq\Context\ApiContext;
use bunq\Context\BunqContext;
use bunq\Model\Generated\Endpoint\MonetaryAccountApiObject;
use bunq\Model\Generated\Endpoint\NoteAttachmentPaymentApiObject;
use bunq\Model\Generated\Endpoint\NoteTextPaymentApiObject;
use bunq\Model\Generated\Endpoint\PaymentApiObject;
use bunq\Model\Generated\Object\AmountObject;
use bunq\Model\Generated\Object\LabelMonetaryAccountObject;
use bunq\Util\BunqEnumApiEnvironmentType;

class BunqService
{
    /** @var array<string, bool> Tracks which API key contexts have been loaded */
    private array $loadedContexts = [];

    private const MAX_PAGES = 20;
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly BunqConfigLoader $configLoader,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, monetary_account_id: int|null}>
     */
    public function listAccounts(): array
    {
        $result = [];

        foreach ($this->configLoader->getAccounts() as $account) {
            $result[] = [
                'key' => $account->key,
                'label' => $account->label,
                'monetary_account_id' => $account->monetaryAccountId,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMonetaryAccounts(?string $accountKey = null): array
    {
        $this->ensureContext($accountKey);

        $accounts = MonetaryAccountApiObject::listing()->getValue();
        $result = [];

        foreach ($accounts as $account) {
            $bank = $account->getMonetaryAccountBank();
            if ($bank === null) {
                continue;
            }

            $balance = $bank->getBalance();
            $result[] = [
                'id' => $bank->getId(),
                'description' => $bank->getDescription(),
                'currency' => $balance instanceof AmountObject ? $balance->getCurrency() : null,
                'balance' => $balance instanceof AmountObject ? $balance->getValue() : null,
                'status' => $bank->getStatus(),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function listTransactions(
        string $accountsParam,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 50,
    ): array {
        $accountKeys = $this->configLoader->resolveAccountKeys($accountsParam);
        $fromDate = $dateFrom !== null ? new \DateTimeImmutable($dateFrom . ' 00:00:00') : null;
        $toDate = $dateTo !== null ? new \DateTimeImmutable($dateTo . ' 23:59:59') : null;
        $perAccountLimit = max(1, min($limit, 500));

        $results = [];

        foreach ($accountKeys as $key) {
            $account = $this->configLoader->getAccount($key);
            $this->ensureContext($key);
            $monetaryAccountId = $account->monetaryAccountId;
            $collected = [];
            $params = ['count' => min($perAccountLimit, self::PAGE_SIZE)];
            $reachedStartBoundary = false;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $response = PaymentApiObject::listing($monetaryAccountId, $params);
                $payments = $response->getValue();

                if (empty($payments)) {
                    break;
                }

                foreach ($payments as $payment) {
                    $paymentDate = new \DateTimeImmutable($payment->getCreated());

                    if ($toDate !== null && $paymentDate > $toDate) {
                        continue;
                    }

                    if ($fromDate !== null && $paymentDate < $fromDate) {
                        $reachedStartBoundary = true;
                        break;
                    }

                    $collected[] = $this->formatPaymentSummary($payment, $key);

                    if (count($collected) >= $perAccountLimit) {
                        break 2;
                    }
                }

                if ($reachedStartBoundary) {
                    break;
                }

                $pagination = $response->getPagination();
                if ($pagination === null || !$pagination->hasPreviousPage()) {
                    break;
                }

                $params = $pagination->getUrlParamsPreviousPage();
            }

            $results[$key] = $collected;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransaction(int $paymentId, ?int $monetaryAccountId = null): array
    {
        $this->ensureContextFromAnyAccount();

        $payment = PaymentApiObject::get($paymentId, $monetaryAccountId)->getValue();

        return $this->formatPaymentDetail($payment);
    }

    /**
     * @return array{text_notes: list<array<string, mixed>>, attachments: list<array<string, mixed>>}
     */
    public function getTransactionNotes(int $paymentId, ?int $monetaryAccountId = null): array
    {
        $this->ensureContextFromAnyAccount();

        $textNotes = [];
        $attachments = [];

        try {
            $noteTexts = NoteTextPaymentApiObject::listing($paymentId, $monetaryAccountId)->getValue();
            foreach ($noteTexts as $note) {
                $textNotes[] = [
                    'id' => $note->getId(),
                    'content' => $note->getContent(),
                    'created' => $note->getCreated(),
                    'updated' => $note->getUpdated(),
                ];
            }
        } catch (\Throwable) {
        }

        try {
            $noteAttachments = NoteAttachmentPaymentApiObject::listing($paymentId, $monetaryAccountId)->getValue();
            foreach ($noteAttachments as $noteAttachment) {
                $attachmentEntries = $noteAttachment->getAttachment() ?? [];
                $attachmentMeta = [];

                foreach ($attachmentEntries as $att) {
                    $attachmentMeta[] = [
                        'id' => $att->getId(),
                        'monetary_account_id' => $att->getMonetaryAccountId(),
                    ];
                }

                $attachments[] = [
                    'id' => $noteAttachment->getId(),
                    'description' => $noteAttachment->getDescription(),
                    'created' => $noteAttachment->getCreated(),
                    'updated' => $noteAttachment->getUpdated(),
                    'attachments' => $attachmentMeta,
                ];
            }
        } catch (\Throwable) {
        }

        return [
            'text_notes' => $textNotes,
            'attachments' => $attachments,
        ];
    }

    private function ensureContext(?string $accountKey = null): void
    {
        $account = $accountKey !== null
            ? $this->configLoader->getAccount($accountKey)
            : $this->getFirstAccount();

        $apiKeyHash = md5($account->apiKey);

        if (isset($this->loadedContexts[$apiKeyHash])) {
            return;
        }

        $contextFile = $this->configLoader->getContextFilePath($account->apiKey);

        if (file_exists($contextFile)) {
            $apiContext = ApiContext::restore($contextFile);
            $apiContext->ensureSessionActive();
            $apiContext->save($contextFile);
        } else {
            $envType = $account->environment === 'sandbox'
                ? BunqEnumApiEnvironmentType::SANDBOX()
                : BunqEnumApiEnvironmentType::PRODUCTION();

            $apiContext = ApiContext::create(
                $envType,
                $account->apiKey,
                'prism',
            );

            $apiContext->save($contextFile);
        }

        BunqContext::loadApiContext($apiContext);
        $this->loadedContexts[$apiKeyHash] = true;
    }

    private function ensureContextFromAnyAccount(): void
    {
        if (!empty($this->loadedContexts)) {
            return;
        }

        $this->ensureContext();
    }

    private function getFirstAccount(): BunqAccountConfig
    {
        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No bunq accounts configured');
        }

        return reset($accounts);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentSummary(PaymentApiObject $payment, string $accountKey): array
    {
        $amount = $payment->getAmount();
        $counterparty = $payment->getCounterpartyAlias();

        return [
            'id' => $payment->getId(),
            'account_key' => $accountKey,
            'created' => $payment->getCreated(),
            'amount' => $amount instanceof AmountObject ? $amount->getValue() : null,
            'currency' => $amount instanceof AmountObject ? $amount->getCurrency() : null,
            'counterparty_name' => $counterparty instanceof LabelMonetaryAccountObject ? $counterparty->getDisplayName() : null,
            'counterparty_iban' => $counterparty instanceof LabelMonetaryAccountObject ? $counterparty->getIban() : null,
            'description' => $payment->getDescription(),
            'type' => $payment->getType(),
            'sub_type' => $payment->getSubType(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentDetail(PaymentApiObject $payment): array
    {
        $amount = $payment->getAmount();
        $balanceAfter = $payment->getBalanceAfterMutation();
        $counterparty = $payment->getCounterpartyAlias();
        $alias = $payment->getAlias();
        $geo = $payment->getGeolocation();

        return [
            'id' => $payment->getId(),
            'created' => $payment->getCreated(),
            'updated' => $payment->getUpdated(),
            'monetary_account_id' => $payment->getMonetaryAccountId(),
            'amount' => $amount instanceof AmountObject ? $amount->getValue() : null,
            'currency' => $amount instanceof AmountObject ? $amount->getCurrency() : null,
            'balance_after_mutation' => $balanceAfter instanceof AmountObject ? $balanceAfter->getValue() : null,
            'description' => $payment->getDescription(),
            'type' => $payment->getType(),
            'sub_type' => $payment->getSubType(),
            'counterparty' => $counterparty instanceof LabelMonetaryAccountObject ? [
                'name' => $counterparty->getDisplayName(),
                'iban' => $counterparty->getIban(),
            ] : null,
            'alias' => $alias instanceof LabelMonetaryAccountObject ? [
                'name' => $alias->getDisplayName(),
                'iban' => $alias->getIban(),
            ] : null,
            'geolocation' => $geo !== null ? [
                'latitude' => $geo->getLatitude(),
                'longitude' => $geo->getLongitude(),
                'altitude' => $geo->getAltitude(),
                'radius' => $geo->getRadius(),
            ] : null,
            'merchant_reference' => $payment->getMerchantReference(),
            'batch_id' => $payment->getBatchId(),
        ];
    }
}
