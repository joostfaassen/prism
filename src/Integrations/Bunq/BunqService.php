<?php

namespace App\Integrations\Bunq;

use bunq\Context\ApiContext;
use bunq\Context\BunqContext;
use bunq\Http\ApiClient;
use bunq\Model\Generated\Endpoint\AttachmentMonetaryAccountContentApiObject;
use bunq\Model\Generated\Endpoint\AttachmentUserContentApiObject;
use bunq\Model\Generated\Endpoint\DraftPaymentApiObject;
use bunq\Model\Generated\Endpoint\MasterCardActionApiObject;
use bunq\Model\Generated\Endpoint\MonetaryAccountApiObject;
use bunq\Model\Generated\Endpoint\PaymentApiObject;
use bunq\Model\Generated\Endpoint\RequestResponseApiObject;
use bunq\Model\Generated\Object\AmountObject;
use bunq\Model\Generated\Object\AttachmentMonetaryAccountPaymentObject;
use bunq\Model\Generated\Object\DraftPaymentEntryObject;
use bunq\Model\Generated\Object\LabelMonetaryAccountObject;
use bunq\Model\Generated\Object\PointerObject;
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
     * Full payment plus text notes and attachment references (not file bytes).
     *
     * @return array<string, mixed>
     */
    public function getTransaction(int $paymentId, ?int $monetaryAccountId = null, ?string $profileKey = null): array
    {
        $this->ensureContext($profileKey);

        $payment = PaymentApiObject::get($paymentId, $monetaryAccountId)->getValue();

        return array_merge(
            $this->formatPaymentDetail($payment),
            $this->resolveNotesForPayment($payment, $monetaryAccountId ?? $payment->getMonetaryAccountId()),
        );
    }

    /**
     * Create a draft payment that must be accepted in the bunq app before money moves.
     *
     * Uses POST /user/{userID}/monetary-account/{monetaryAccountID}/draft-payment.
     * Counterparty may be IBAN (name required), EMAIL, or PHONE_NUMBER.
     *
     * @return array<string, mixed>
     */
    public function createDraftPayment(
        string $profileKey,
        string $amount,
        string $currency,
        string $counterpartyType,
        string $counterpartyValue,
        string $description,
        ?string $counterpartyName = null,
        ?int $monetaryAccountId = null,
        ?string $merchantReference = null,
    ): array {
        $profile = $this->configLoader->getProfile($profileKey);
        $this->ensureContext($profileKey);

        $normalizedAmount = $this->normalizeAmount($amount);
        $normalizedCurrency = strtoupper(trim($currency));
        $normalizedType = strtoupper(trim($counterpartyType));
        $normalizedValue = $this->normalizeCounterpartyValue($normalizedType, $counterpartyValue);
        $normalizedName = $counterpartyName !== null ? trim($counterpartyName) : null;

        $this->assertDraftPaymentArguments(
            $normalizedAmount,
            $normalizedCurrency,
            $normalizedType,
            $normalizedValue,
            $normalizedName,
            $description,
        );

        $resolvedMonetaryAccountId = $this->resolveSingleMonetaryAccountId($profile, $monetaryAccountId);

        $pointer = new PointerObject(
            $normalizedType,
            $normalizedValue,
            $normalizedType === 'IBAN' ? $normalizedName : ($normalizedName !== '' ? $normalizedName : null),
        );

        $entry = new DraftPaymentEntryObject(
            new AmountObject($normalizedAmount, $normalizedCurrency),
            $pointer,
            $description,
            $merchantReference,
        );

        $draftPaymentId = DraftPaymentApiObject::create(
            [$entry],
            1,
            $resolvedMonetaryAccountId,
        )->getValue();

        $draft = DraftPaymentApiObject::get($draftPaymentId, $resolvedMonetaryAccountId)->getValue();

        return $this->formatDraftPayment($draft, $profileKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDraftPayment(DraftPaymentApiObject $draft, string $profileKey): array
    {
        $entries = [];
        foreach ($draft->getEntries() ?? [] as $entry) {
            $amount = $entry->getAmount();
            $counterparty = $entry->getCounterpartyAlias();

            $entries[] = [
                'id' => $entry->getId(),
                'amount' => $amount instanceof AmountObject ? $amount->getValue() : null,
                'currency' => $amount instanceof AmountObject ? $amount->getCurrency() : null,
                'description' => $entry->getDescription(),
                'merchant_reference' => $entry->getMerchantReference(),
                'type' => $entry->getType(),
                'counterparty_name' => $counterparty instanceof LabelMonetaryAccountObject
                    ? $counterparty->getDisplayName()
                    : null,
                'counterparty_iban' => $counterparty instanceof LabelMonetaryAccountObject
                    ? $counterparty->getIban()
                    : null,
            ];
        }

        return [
            'id' => $draft->getId(),
            'profile_key' => $profileKey,
            'monetary_account_id' => $draft->getMonetaryAccountId(),
            'status' => $draft->getStatus(),
            'type' => $draft->getType(),
            'entries' => $entries,
            'next_step' => 'Open the bunq app and accept this draft payment to execute the transfer. '
                . 'No money has been sent yet.',
        ];
    }

    private function normalizeAmount(string $amount): string
    {
        $trimmed = trim(str_replace(',', '.', $amount));

        if (!is_numeric($trimmed)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid amount "%s". Use a positive decimal like "12.50".',
                $amount,
            ));
        }

        $value = (float) $trimmed;
        if ($value <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        return number_format($value, 2, '.', '');
    }

    private function normalizeCounterpartyValue(string $type, string $value): string
    {
        $trimmed = trim($value);

        return match ($type) {
            'IBAN' => strtoupper(preg_replace('/\s+/', '', $trimmed) ?? $trimmed),
            'PHONE_NUMBER' => preg_replace('/\s+/', '', $trimmed) ?? $trimmed,
            'EMAIL' => strtolower($trimmed),
            default => $trimmed,
        };
    }

    private function assertDraftPaymentArguments(
        string $amount,
        string $currency,
        string $type,
        string $value,
        ?string $name,
        string $description,
    ): void {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid currency "%s". Use an ISO 4217 code like "EUR".',
                $currency,
            ));
        }

        $allowedTypes = ['IBAN', 'EMAIL', 'PHONE_NUMBER'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid counterparty_type "%s". Allowed: %s',
                $type,
                implode(', ', $allowedTypes),
            ));
        }

        if ($value === '') {
            throw new \InvalidArgumentException('counterparty_value is required.');
        }

        if ($type === 'IBAN') {
            if ($name === null || $name === '') {
                throw new \InvalidArgumentException(
                    'counterparty_name is required when counterparty_type is IBAN.',
                );
            }

            if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid IBAN "%s".',
                    $value,
                ));
            }

            // External IBAN descriptions are capped at 140 characters by bunq.
            if (mb_strlen($description) > 140) {
                throw new \InvalidArgumentException(
                    'description must be at most 140 characters for IBAN draft payments.',
                );
            }
        }

        if ($type === 'EMAIL' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Invalid email "%s".', $value));
        }

        if ($type === 'PHONE_NUMBER' && !preg_match('/^\+[1-9]\d{6,14}$/', $value)) {
            throw new \InvalidArgumentException(
                'Phone numbers must be E.123 without spaces (e.g. +31612345678).',
            );
        }

        if (mb_strlen($description) > 9000) {
            throw new \InvalidArgumentException('description must be at most 9000 characters.');
        }
    }

    /**
     * Prefer an explicit monetary_account_id, then the profile default, else the only active account.
     */
    private function resolveSingleMonetaryAccountId(BunqProfileConfig $profile, ?int $monetaryAccountId): int
    {
        if ($monetaryAccountId !== null) {
            return $monetaryAccountId;
        }

        if ($profile->monetaryAccountId !== null) {
            return $profile->monetaryAccountId;
        }

        $ids = $this->resolveMonetaryAccountIds($profile);

        if (count($ids) === 1) {
            return $ids[0];
        }

        throw new \InvalidArgumentException(sprintf(
            'Profile "%s" has multiple monetary accounts (%s). Pass monetary_account_id explicitly.',
            $profile->key,
            implode(', ', $ids),
        ));
    }

    /**
     * @return array{
     *     mastercard_action_id: int|null,
     *     request_response_id: int|null,
     *     notes: list<array<string, mixed>>,
     *     attachments: list<array<string, mixed>>,
     *     attachment_ids: list<int>,
     *     has_notes: bool,
     *     has_attachments: bool,
     *     errors: list<string>
     * }
     */
    private function resolveNotesForPayment(PaymentApiObject $payment, ?int $monetaryAccountId): array
    {
        $errors = [];
        $mastercardActionId = null;
        $requestResponseId = null;
        $paymentId = (int) $payment->getId();
        $paymentAttachments = $this->formatAttachmentRefs($payment->getAttachment() ?? []);
        $paymentType = $payment->getType();
        $skipPaymentNotes = $paymentType === 'MASTERCARD' || $paymentType === 'FIS';

        $notes = [
            'text_notes' => [],
            'attachments' => [],
            'errors' => [],
            'notes_unsupported' => $skipPaymentNotes,
        ];

        if (!$skipPaymentNotes) {
            $notes = $this->collectPaymentNotes($paymentId, $monetaryAccountId);
        }

        $needsFallback = $monetaryAccountId !== null && ($notes['notes_unsupported'] || $skipPaymentNotes);

        if ($needsFallback && ($paymentType === 'MASTERCARD' || $paymentType === 'FIS')) {
            try {
                $action = $this->findMasterCardActionForPayment($payment, $monetaryAccountId);
                if ($action !== null) {
                    $mastercardActionId = (int) $action->getId();
                    $mcNotes = $this->collectMasterCardNotes($mastercardActionId, $monetaryAccountId);
                    $notes['text_notes'] = array_merge($notes['text_notes'], $mcNotes['text_notes']);
                    $notes['attachments'] = array_merge($notes['attachments'], $mcNotes['attachments']);
                    $notes['errors'] = array_merge($notes['errors'], $mcNotes['errors']);
                }
            } catch (\Throwable $e) {
                $errors[] = 'mastercard_action: ' . $e->getMessage();
            }
        }

        if ($needsFallback && $notes['text_notes'] === [] && $notes['attachments'] === []) {
            try {
                $requestResponseId = $this->findRequestResponseForPayment($payment, $monetaryAccountId);
                if ($requestResponseId !== null) {
                    $rrNotes = $this->collectRequestResponseNotes($requestResponseId, $monetaryAccountId);
                    $notes['text_notes'] = array_merge($notes['text_notes'], $rrNotes['text_notes']);
                    $notes['attachments'] = array_merge($notes['attachments'], $rrNotes['attachments']);
                    $notes['errors'] = array_merge($notes['errors'], $rrNotes['errors']);
                }
            } catch (\Throwable $e) {
                $errors[] = 'request_response: ' . $e->getMessage();
            }
        }

        $attachmentIds = [];
        foreach (array_merge($notes['attachments'], [['attachments' => $paymentAttachments]]) as $note) {
            foreach ($note['attachments'] ?? [] as $att) {
                if (isset($att['id']) && $att['id'] !== null) {
                    $attachmentIds[] = (int) $att['id'];
                }
            }
        }
        $attachmentIds = array_values(array_unique($attachmentIds));

        return [
            'mastercard_action_id' => $mastercardActionId,
            'request_response_id' => $requestResponseId,
            'notes' => $notes['text_notes'],
            'attachments' => $notes['attachments'],
            'attachment_ids' => $attachmentIds,
            'has_notes' => $notes['text_notes'] !== [],
            'has_attachments' => $attachmentIds !== [],
            'errors' => array_merge($errors, $notes['errors']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectPaymentRaw(int $paymentId, int $monetaryAccountId, ?string $profileKey = null): array
    {
        $this->ensureContext($profileKey);
        $base = sprintf(
            'user/%d/monetary-account/%d/payment/%d',
            $this->bunqUserId(),
            $monetaryAccountId,
            $paymentId,
        );

        $out = [
            'payment' => $this->bunqGet($base),
            'note_text' => $this->safeBunqGet($base . '/note-text'),
            'note_attachment' => $this->safeBunqGet($base . '/note-attachment'),
        ];

        $events = $this->safeBunqGet(
            sprintf('user/%d/event', $this->bunqUserId()),
            [
                'monetary_account_id' => (string) $monetaryAccountId,
                'count' => '50',
            ],
        );
        $out['events_matching_payment'] = [];
        $requestResponseIds = [];
        foreach ($events['Response'] ?? [] as $item) {
            $encoded = json_encode($item) ?: '';
            if (str_contains($encoded, (string) $paymentId)) {
                $out['events_matching_payment'][] = $item;
                $rrId = $item['Event']['object']['RequestResponse']['id'] ?? null;
                if (is_int($rrId) || (is_string($rrId) && ctype_digit($rrId))) {
                    $requestResponseIds[] = (int) $rrId;
                }
            }
        }

        $out['request_response_notes'] = [];
        foreach (array_unique($requestResponseIds) as $rrId) {
            $rrBase = sprintf(
                'user/%d/monetary-account/%d/request-response/%d',
                $this->bunqUserId(),
                $monetaryAccountId,
                $rrId,
            );
            $out['request_response_notes'][$rrId] = [
                'note_text' => $this->safeBunqGet($rrBase . '/note-text'),
                'note_attachment' => $this->safeBunqGet($rrBase . '/note-attachment'),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function safeBunqGet(string $path, array $params = []): array
    {
        try {
            return $this->bunqGet($path, $params);
        } catch (\Throwable $e) {
            return ['_error' => $e->getMessage()];
        }
    }

    /**
     * Download raw attachment bytes. Tries monetary-account content first, then user content.
     *
     * @return array{attachment_id: int, monetary_account_id: int|null, mime_type: string, bytes: int, content: string, source: string}
     */
    public function getAttachmentContent(
        int $attachmentId,
        ?int $monetaryAccountId = null,
        ?string $profileKey = null,
    ): array {
        $this->ensureContext($profileKey);

        $lastError = null;

        if ($monetaryAccountId !== null) {
            try {
                return $this->readAttachmentContent(
                    AttachmentMonetaryAccountContentApiObject::listing($attachmentId, $monetaryAccountId),
                    $attachmentId,
                    $monetaryAccountId,
                    'monetary_account',
                );
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        try {
            return $this->readAttachmentContent(
                AttachmentUserContentApiObject::listing($attachmentId),
                $attachmentId,
                $monetaryAccountId,
                'user',
            );
        } catch (\Throwable $e) {
            $message = $lastError !== null
                ? $lastError->getMessage() . ' / ' . $e->getMessage()
                : $e->getMessage();

            throw new \RuntimeException(sprintf(
                'Could not download bunq attachment %d: %s',
                $attachmentId,
                $message,
            ), 0, $e);
        }
    }

    /**
     * Walk recent payments and card actions and return those with notes or attachments.
     *
     * @return list<array<string, mixed>>
     */
    public function scanTransactionAttachments(
        string $profilesParam,
        int $limit = 25,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $monetaryAccountId = null,
    ): array {
        $profileKeys = $this->configLoader->resolveProfileKeys($profilesParam);
        $fromDate = $dateFrom !== null ? new \DateTimeImmutable($dateFrom . ' 00:00:00') : null;
        $toDate = $dateTo !== null ? new \DateTimeImmutable($dateTo . ' 23:59:59') : null;
        $perAccountLimit = max(1, min($limit, 200));
        $hits = [];

        foreach ($profileKeys as $key) {
            $profile = $this->configLoader->getProfile($key);
            $this->ensureContext($key);

            $accountIds = $this->resolveMonetaryAccountIds($profile);
            if ($monetaryAccountId !== null) {
                if (!in_array($monetaryAccountId, $accountIds, true)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Monetary account %d is not on bunq profile "%s".',
                        $monetaryAccountId,
                        $key,
                    ));
                }
                $accountIds = [$monetaryAccountId];
            }

            foreach ($accountIds as $accountId) {
                try {
                    $payments = $this->fetchPaymentObjectsForAccount(
                        $accountId,
                        $fromDate,
                        $toDate,
                        $perAccountLimit,
                    );
                } catch (\Throwable $e) {
                    $hits[] = [
                        'source' => 'payment',
                        'profile_key' => $key,
                        'payment_id' => null,
                        'mastercard_action_id' => null,
                        'monetary_account_id' => $accountId,
                        'created' => null,
                        'amount' => null,
                        'currency' => null,
                        'counterparty_name' => null,
                        'description' => null,
                        'type' => null,
                        'text_note_count' => 0,
                        'attachment_count' => 0,
                        'payment_attachment_count' => 0,
                        'text_notes' => [],
                        'attachments' => [],
                        'payment_attachments' => [],
                        'errors' => ['payment_list: ' . $e->getMessage()],
                    ];
                    continue;
                }

                foreach ($payments as $payment) {
                    $paymentType = $payment->getType();
                    $skipPaymentNotes = $paymentType === 'MASTERCARD' || $paymentType === 'FIS';
                    $notes = $skipPaymentNotes
                        ? ['text_notes' => [], 'attachments' => [], 'errors' => [], 'notes_unsupported' => true]
                        : $this->collectPaymentNotes((int) $payment->getId(), $accountId);
                    $paymentAttachments = $this->formatAttachmentRefs($payment->getAttachment() ?? []);

                    if (
                        $notes['text_notes'] === []
                        && $notes['attachments'] === []
                        && $paymentAttachments === []
                        && $notes['errors'] === []
                    ) {
                        continue;
                    }

                    $hits[] = $this->formatScanHit(
                        source: 'payment',
                        profileKey: $key,
                        monetaryAccountId: $accountId,
                        payment: $payment,
                        textNotes: $notes['text_notes'],
                        attachments: $notes['attachments'],
                        paymentAttachments: $paymentAttachments,
                        errors: $notes['errors'],
                    );
                }

                $hits = array_merge(
                    $hits,
                    $this->scanMasterCardActionNotes(
                        $key,
                        $accountId,
                        $perAccountLimit,
                    ),
                );
            }
        }

        return $hits;
    }

    /**
     * @return array{text_notes: list<array<string, mixed>>, attachments: list<array<string, mixed>>, errors: list<string>, notes_unsupported: bool}
     */
    private function collectPaymentNotes(int $paymentId, ?int $monetaryAccountId): array
    {
        $accountId = $monetaryAccountId ?? 0;
        $base = sprintf(
            'user/%d/monetary-account/%d/payment/%d',
            $this->bunqUserId(),
            $accountId,
            $paymentId,
        );

        return $this->collectNotesFromPaths($base . '/note-text', $base . '/note-attachment', 'payment');
    }

    /**
     * @return array{text_notes: list<array<string, mixed>>, attachments: list<array<string, mixed>>, errors: list<string>}
     */
    private function collectMasterCardNotes(int $actionId, int $monetaryAccountId): array
    {
        $base = sprintf(
            'user/%d/monetary-account/%d/mastercard-action/%d',
            $this->bunqUserId(),
            $monetaryAccountId,
            $actionId,
        );

        $notes = $this->collectNotesFromPaths($base . '/note-text', $base . '/note-attachment', 'mastercard_action', $actionId);
        unset($notes['notes_unsupported']);

        return $notes;
    }

    /**
     * @return array{text_notes: list<array<string, mixed>>, attachments: list<array<string, mixed>>, errors: list<string>}
     */
    private function collectRequestResponseNotes(int $requestResponseId, int $monetaryAccountId): array
    {
        $base = sprintf(
            'user/%d/monetary-account/%d/request-response/%d',
            $this->bunqUserId(),
            $monetaryAccountId,
            $requestResponseId,
        );

        $notes = $this->collectNotesFromPaths($base . '/note-text', $base . '/note-attachment', 'request_response');
        unset($notes['notes_unsupported']);

        return $notes;
    }

    private function findRequestResponseForPayment(PaymentApiObject $payment, int $monetaryAccountId): ?int
    {
        $paymentAmount = $payment->getAmount() instanceof AmountObject ? $payment->getAmount()->getValue() : null;
        $paymentDesc = $this->normalizeDescription($payment->getDescription());
        $bestId = null;
        $bestScore = 0;

        $items = RequestResponseApiObject::listing($monetaryAccountId, ['count' => '100'])->getValue();
        foreach ($items as $request) {
            $amount = $request->getAmountResponded() ?? $request->getAmountInquired();
            $requestAmount = $amount instanceof AmountObject ? $amount->getValue() : null;
            $requestDesc = $this->normalizeDescription($request->getDescription());
            $score = 0;

            if ($paymentAmount !== null && $requestAmount !== null && $this->sameAbsoluteAmount($paymentAmount, $requestAmount)) {
                $score += 2;
            }
            if ($paymentDesc !== '' && $requestDesc !== '' && $paymentDesc === $requestDesc) {
                $score += 3;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $request->getId();
            }
        }

        return $bestScore >= 3 ? $bestId : null;
    }

    /**
     * Parse note-text and note-attachment listings from raw bunq JSON.
     * The official SDK crashes when attachment entries are bare IDs instead of objects.
     *
     * @return array{text_notes: list<array<string, mixed>>, attachments: list<array<string, mixed>>, errors: list<string>, notes_unsupported: bool}
     */
    private function collectNotesFromPaths(
        string $textPath,
        string $attachmentPath,
        string $source,
        ?int $mastercardActionId = null,
    ): array {
        $textNotes = [];
        $attachments = [];
        $errors = [];
        $notesUnsupported = false;

        try {
            foreach ($this->listNoteTexts($textPath) as $note) {
                $note['source'] = $source;
                if ($mastercardActionId !== null) {
                    $note['mastercard_action_id'] = $mastercardActionId;
                }
                $textNotes[] = $note;
            }
        } catch (\Throwable $e) {
            if ($this->isNotesUnsupported($e)) {
                $notesUnsupported = true;
            } else {
                $errors[] = $source . '_note_text: ' . $e->getMessage();
            }
        }

        try {
            foreach ($this->listNoteAttachments($attachmentPath, $source, $mastercardActionId) as $note) {
                $attachments[] = $note;
            }
        } catch (\Throwable $e) {
            if ($this->isNotesUnsupported($e)) {
                $notesUnsupported = true;
            } else {
                $errors[] = $source . '_note_attachment: ' . $e->getMessage();
            }
        }

        return [
            'text_notes' => $textNotes,
            'attachments' => $attachments,
            'errors' => $errors,
            'notes_unsupported' => $notesUnsupported,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listNoteTexts(string $path): array
    {
        $out = [];
        foreach ($this->unwrapBunqResponse($this->bunqGet($path)) as $item) {
            $note = $this->unwrapNamedObject($item, ['NoteText']);
            if ($note === null) {
                continue;
            }

            $out[] = [
                'id' => $note['id'] ?? null,
                'content' => $note['content'] ?? null,
                'created' => $note['created'] ?? null,
                'updated' => $note['updated'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listNoteAttachments(string $path, string $source, ?int $mastercardActionId): array
    {
        $out = [];
        foreach ($this->unwrapBunqResponse($this->bunqGet($path)) as $item) {
            $note = $this->unwrapNamedObject($item, ['NoteAttachment']);
            if ($note === null) {
                continue;
            }

            $out[] = [
                'id' => $note['id'] ?? null,
                'source' => $source,
                'mastercard_action_id' => $mastercardActionId,
                'description' => $note['description'] ?? null,
                'created' => $note['created'] ?? null,
                'updated' => $note['updated'] ?? null,
                'attachments' => $this->parseAttachmentRefs($note['attachment'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return list<mixed>
     */
    private function unwrapBunqResponse(array $decoded): array
    {
        $response = $decoded['Response'] ?? [];

        return is_array($response) ? array_values($response) : [];
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>|null
     */
    private function unwrapNamedObject(mixed $item, array $names): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        foreach ($names as $name) {
            if (isset($item[$name]) && is_array($item[$name])) {
                return $item[$name];
            }
        }

        $inner = reset($item);

        return is_array($inner) ? $inner : null;
    }

    /**
     * @return list<array{id: int|null, monetary_account_id: int|null}>
     */
    private function parseAttachmentRefs(mixed $attachments): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $out = [];
        foreach ($attachments as $att) {
            if (is_int($att) || (is_string($att) && ctype_digit($att))) {
                $out[] = ['id' => (int) $att, 'monetary_account_id' => null];
                continue;
            }

            if (!is_array($att)) {
                continue;
            }

            if (isset($att['id'])) {
                $out[] = [
                    'id' => isset($att['id']) ? (int) $att['id'] : null,
                    'monetary_account_id' => isset($att['monetary_account_id']) ? (int) $att['monetary_account_id'] : null,
                ];
                continue;
            }

            $inner = reset($att);
            if (is_int($inner) || (is_string($inner) && ctype_digit($inner))) {
                $out[] = ['id' => (int) $inner, 'monetary_account_id' => null];
                continue;
            }

            if (is_array($inner) && isset($inner['id'])) {
                $out[] = [
                    'id' => (int) $inner['id'],
                    'monetary_account_id' => isset($inner['monetary_account_id']) ? (int) $inner['monetary_account_id'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function bunqGet(string $path, array $params = []): array
    {
        $client = new ApiClient(BunqContext::getApiContext());
        $raw = $client->get($path, $params, []);
        $body = (string) $raw->getBodyString();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid bunq JSON from ' . $path);
        }

        return $decoded;
    }

    private function bunqUserId(): int
    {
        return BunqContext::getUserContext()->getUserId();
    }

    private function findMasterCardActionForPayment(PaymentApiObject $payment, int $monetaryAccountId): ?MasterCardActionApiObject
    {
        $paymentAmount = $payment->getAmount() instanceof AmountObject ? $payment->getAmount()->getValue() : null;
        $paymentDesc = $this->normalizeDescription($payment->getDescription());
        $best = null;
        $bestScore = 0;

        $actions = MasterCardActionApiObject::listing($monetaryAccountId, ['count' => 100])->getValue();
        foreach ($actions as $action) {
            $actionAmount = null;
            if ($action->getAmountBilling() instanceof AmountObject) {
                $actionAmount = $action->getAmountBilling()->getValue();
            } elseif ($action->getAmountLocal() instanceof AmountObject) {
                $actionAmount = $action->getAmountLocal()->getValue();
            }

            $actionDesc = $this->normalizeDescription($action->getDescription());
            $score = 0;

            if ($paymentAmount !== null && $actionAmount !== null && $this->sameAbsoluteAmount($paymentAmount, $actionAmount)) {
                $score += 2;
            }
            if ($paymentDesc !== '' && $actionDesc !== '' && $paymentDesc === $actionDesc) {
                $score += 3;
            } elseif ($paymentDesc !== '' && $actionDesc !== '' && (
                str_contains($actionDesc, $paymentDesc) || str_contains($paymentDesc, $actionDesc)
            )) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $action;
            }
        }

        return $bestScore >= 3 ? $best : null;
    }

    private function isNotesUnsupported(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'does note support notes')
            || str_contains($message, 'does not support notes');
    }

    private function normalizeDescription(?string $description): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $description ?? '') ?? ''));

        return $normalized;
    }

    private function sameAbsoluteAmount(string $left, string $right): bool
    {
        return abs(abs((float) $left) - abs((float) $right)) < 0.015;
    }

    /**
     * @return list<PaymentApiObject>
     */
    private function fetchPaymentObjectsForAccount(
        int $monetaryAccountId,
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

                $collected[] = $payment;

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
     * @return list<array<string, mixed>>
     */
    private function scanMasterCardActionNotes(
        string $profileKey,
        int $monetaryAccountId,
        int $limit,
    ): array {
        $hits = [];
        $params = ['count' => min($limit, self::PAGE_SIZE)];

        try {
            $actions = MasterCardActionApiObject::listing($monetaryAccountId, $params)->getValue();
        } catch (\Throwable $e) {
            return [[
                'source' => 'mastercard_action',
                'profile_key' => $profileKey,
                'payment_id' => null,
                'mastercard_action_id' => null,
                'monetary_account_id' => $monetaryAccountId,
                'created' => null,
                'amount' => null,
                'currency' => null,
                'counterparty_name' => null,
                'description' => null,
                'type' => 'MASTERCARD',
                'text_note_count' => 0,
                'attachment_count' => 0,
                'payment_attachment_count' => 0,
                'text_notes' => [],
                'attachments' => [],
                'payment_attachments' => [],
                'errors' => ['mastercard_action_list: ' . $e->getMessage()],
            ]];
        }

        $inspected = 0;
        foreach ($actions as $action) {
            ++$inspected;
            if ($inspected > $limit) {
                break;
            }

            $actionId = (int) $action->getId();
            $mcNotes = $this->collectMasterCardNotes($actionId, $monetaryAccountId);
            $textNotes = $mcNotes['text_notes'];
            $attachments = $mcNotes['attachments'];
            $errors = $mcNotes['errors'];

            if ($textNotes === [] && $attachments === [] && $errors === []) {
                continue;
            }

            $amount = $action->getAmountBilling() ?? $action->getAmountLocal();
            $counterparty = $action->getCounterpartyAlias();

            $hits[] = [
                'source' => 'mastercard_action',
                'profile_key' => $profileKey,
                'payment_id' => null,
                'mastercard_action_id' => $actionId,
                'monetary_account_id' => $monetaryAccountId,
                'created' => $action->getMaturityDate(),
                'amount' => $amount instanceof AmountObject ? $amount->getValue() : null,
                'currency' => $amount instanceof AmountObject ? $amount->getCurrency() : null,
                'counterparty_name' => $counterparty instanceof LabelMonetaryAccountObject
                    ? $counterparty->getDisplayName()
                    : null,
                'description' => $action->getDescription(),
                'type' => 'MASTERCARD',
                'text_note_count' => count($textNotes),
                'attachment_count' => count($attachments),
                'payment_attachment_count' => 0,
                'text_notes' => $textNotes,
                'attachments' => $attachments,
                'payment_attachments' => [],
                'errors' => $errors,
            ];
        }

        return $hits;
    }

    /**
     * @param list<array<string, mixed>> $textNotes
     * @param list<array<string, mixed>> $attachments
     * @param list<array<string, mixed>> $paymentAttachments
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function formatScanHit(
        string $source,
        string $profileKey,
        int $monetaryAccountId,
        PaymentApiObject $payment,
        array $textNotes,
        array $attachments,
        array $paymentAttachments,
        array $errors,
    ): array {
        $amount = $payment->getAmount();
        $counterparty = $payment->getCounterpartyAlias();

        return [
            'source' => $source,
            'profile_key' => $profileKey,
            'payment_id' => $payment->getId(),
            'mastercard_action_id' => null,
            'monetary_account_id' => $monetaryAccountId,
            'created' => $payment->getCreated(),
            'amount' => $amount instanceof AmountObject ? $amount->getValue() : null,
            'currency' => $amount instanceof AmountObject ? $amount->getCurrency() : null,
            'counterparty_name' => $counterparty instanceof LabelMonetaryAccountObject
                ? $counterparty->getDisplayName()
                : null,
            'description' => $payment->getDescription(),
            'type' => $payment->getType(),
            'text_note_count' => count($textNotes),
            'attachment_count' => count($attachments),
            'payment_attachment_count' => count($paymentAttachments),
            'text_notes' => $textNotes,
            'attachments' => $attachments,
            'payment_attachments' => $paymentAttachments,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<AttachmentMonetaryAccountPaymentObject> $attachments
     *
     * @return list<array{id: int|null, monetary_account_id: int|null}>
     */
    private function formatAttachmentRefs(array $attachments): array
    {
        $attachmentMeta = [];

        foreach ($attachments as $att) {
            if (!$att instanceof AttachmentMonetaryAccountPaymentObject) {
                continue;
            }

            $attachmentMeta[] = [
                'id' => $att->getId(),
                'monetary_account_id' => $att->getMonetaryAccountId(),
            ];
        }

        return $attachmentMeta;
    }

    /**
     * @return array{attachment_id: int, monetary_account_id: int|null, mime_type: string, bytes: int, content: string, source: string}
     */
    private function readAttachmentContent(
        \bunq\Model\Generated\Endpoint\BunqResponseString $response,
        int $attachmentId,
        ?int $monetaryAccountId,
        string $source,
    ): array {
        $bytes = (string) $response->getValue();
        if ($bytes === '') {
            throw new \RuntimeException('Attachment content was empty');
        }

        $mimeType = $this->extractContentType($response->getHeaders()) ?? $this->detectMimeType($bytes);

        return [
            'attachment_id' => $attachmentId,
            'monetary_account_id' => $monetaryAccountId,
            'mime_type' => $mimeType,
            'bytes' => strlen($bytes),
            'content' => $bytes,
            'source' => $source,
        ];
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function extractContentType(array $headers): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, 'Content-Type') !== 0) {
                continue;
            }

            $type = strtolower(trim(explode(';', (string) $value, 2)[0]));

            return $type !== '' && $type !== 'application/json' ? $type : null;
        }

        return null;
    }

    private function detectMimeType(string $bytes): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($bytes);

        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
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
