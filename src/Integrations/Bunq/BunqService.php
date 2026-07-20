<?php

namespace App\Integrations\Bunq;

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
    public function listProfiles(): array
    {
        $result = [];

        foreach ($this->configLoader->getProfiles() as $profile) {
            $result[] = [
                'key' => $profile->key,
                'label' => $profile->label,
                'monetary_account_id' => $profile->monetaryAccountId,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMonetaryAccounts(?string $profileKey = null): array
    {
        $this->ensureContext($profileKey);

        $monetaryAccounts = MonetaryAccountApiObject::listing()->getValue();
        $result = [];

        foreach ($monetaryAccounts as $account) {
            $inner = $account->getMonetaryAccountBank()
                ?? $account->getMonetaryAccountJoint()
                ?? $account->getMonetaryAccountSavings()
                ?? $account->getMonetaryAccountExternal()
                ?? $account->getMonetaryAccountExternalSavings();

            if ($inner === null) {
                continue;
            }

            $balance = $inner->getBalance();
            $iban = $this->extractIban($inner->getAlias() ?? []);

            $result[] = [
                'id' => $inner->getId(),
                'description' => $inner->getDescription(),
                'currency' => $balance instanceof AmountObject ? $balance->getCurrency() : null,
                'balance' => $balance instanceof AmountObject ? $balance->getValue() : null,
                'iban' => $iban,
                'status' => $inner->getStatus(),
                'type' => $this->resolveAccountType($account),
            ];
        }

        return $result;
    }

    private function resolveAccountType(MonetaryAccountApiObject $account): string
    {
        if ($account->getMonetaryAccountBank() !== null) {
            return 'bank';
        }
        if ($account->getMonetaryAccountJoint() !== null) {
            return 'joint';
        }
        if ($account->getMonetaryAccountSavings() !== null) {
            return 'savings';
        }
        if ($account->getMonetaryAccountExternal() !== null) {
            return 'external';
        }
        if ($account->getMonetaryAccountExternalSavings() !== null) {
            return 'external_savings';
        }

        return 'unknown';
    }

    /**
     * @param array<mixed> $aliases
     */
    private function extractIban(array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (is_object($alias) && method_exists($alias, 'getType') && $alias->getType() === 'IBAN') {
                return $alias->getValue();
            }
        }

        return null;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function listTransactions(
        string $profilesParam,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 50,
    ): array {
        $profileKeys = $this->configLoader->resolveProfileKeys($profilesParam);
        $fromDate = $dateFrom !== null ? new \DateTimeImmutable($dateFrom . ' 00:00:00') : null;
        $toDate = $dateTo !== null ? new \DateTimeImmutable($dateTo . ' 23:59:59') : null;
        $perAccountLimit = max(1, min($limit, 500));

        $results = [];

        foreach ($profileKeys as $key) {
            $profile = $this->configLoader->getProfile($key);
            $this->ensureContext($key);

            $monetaryAccountIds = $this->resolveMonetaryAccountIds($profile);

            foreach ($monetaryAccountIds as $monetaryAccountId) {
                $resultKey = count($monetaryAccountIds) > 1
                    ? $key . '/' . $monetaryAccountId
                    : $key;

                $collected = $this->fetchPaymentsForAccount(
                    $monetaryAccountId,
                    $key,
                    $fromDate,
                    $toDate,
                    $perAccountLimit,
                );

                $results[$resultKey] = $collected;
            }
        }

        return $results;
    }

    /**
     * When monetary_account_id is null, discovers all active monetary accounts.
     *
     * @return list<int>
     */
    private function resolveMonetaryAccountIds(BunqProfileConfig $profile): array
    {
        if ($profile->monetaryAccountId !== null) {
            return [$profile->monetaryAccountId];
        }

        $monetaryAccounts = MonetaryAccountApiObject::listing()->getValue();
        $ids = [];

        foreach ($monetaryAccounts as $ma) {
            $inner = $ma->getMonetaryAccountBank()
                ?? $ma->getMonetaryAccountJoint()
                ?? $ma->getMonetaryAccountSavings()
                ?? $ma->getMonetaryAccountExternal()
                ?? $ma->getMonetaryAccountExternalSavings();

            if ($inner === null) {
                continue;
            }

            if ($inner->getStatus() === 'ACTIVE') {
                $ids[] = $inner->getId();
            }
        }

        if (empty($ids)) {
            throw new \RuntimeException(sprintf(
                'No active monetary accounts found for bunq profile "%s"',
                $profile->key,
            ));
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPaymentsForAccount(
        int $monetaryAccountId,
        string $profileKey,
        ?\DateTimeImmutable $fromDate,
        ?\DateTimeImmutable $toDate,
        int $perAccountLimit,
    ): array {
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

                $collected[] = $this->formatPaymentSummary($payment, $profileKey);

                if (count($collected) >= $perAccountLimit) {
                    return $collected;
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

        return $collected;
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

    private function ensureContext(?string $profileKey = null): void
    {
        $profile = $profileKey !== null
            ? $this->configLoader->getProfile($profileKey)
            : $this->getFirstAccount();

        $contextKey = $profile->configFile ?? md5($profile->apiKey);

        if (isset($this->loadedContexts[$contextKey])) {
            return;
        }

        if ($profile->configFile !== null) {
            if (!file_exists($profile->configFile)) {
                throw new \RuntimeException('bunq config file not found: ' . $profile->configFile);
            }

            $apiContext = ApiContext::restore($profile->configFile);
            $apiContext->ensureSessionActive();
            $apiContext->save($profile->configFile);
        } else {
            if ($profile->apiKey === '') {
                throw new \RuntimeException(sprintf(
                    'bunq profile "%s" has no api_key or config_file configured',
                    $profile->key,
                ));
            }

            $contextFile = $this->configLoader->getContextFilePath($profile->apiKey);

            if (file_exists($contextFile)) {
                $apiContext = ApiContext::restore($contextFile);
                $apiContext->ensureSessionActive();
                $apiContext->save($contextFile);
            } else {
                $envType = $profile->environment === 'sandbox'
                    ? BunqEnumApiEnvironmentType::SANDBOX()
                    : BunqEnumApiEnvironmentType::PRODUCTION();

                $apiContext = ApiContext::create(
                    $envType,
                    $profile->apiKey,
                    'prism',
                );

                $apiContext->save($contextFile);
            }
        }

        BunqContext::loadApiContext($apiContext);
        $this->loadedContexts[$contextKey] = true;
    }

    private function ensureContextFromAnyAccount(): void
    {
        if (!empty($this->loadedContexts)) {
            return;
        }

        $this->ensureContext();
    }

    private function getFirstAccount(): BunqProfileConfig
    {
        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No bunq profiles configured');
        }

        return reset($profiles);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPaymentSummary(PaymentApiObject $payment, string $profileKey): array
    {
        $amount = $payment->getAmount();
        $counterparty = $payment->getCounterpartyAlias();

        return [
            'id' => $payment->getId(),
            'profile_key' => $profileKey,
            'monetary_account_id' => $payment->getMonetaryAccountId(),
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
