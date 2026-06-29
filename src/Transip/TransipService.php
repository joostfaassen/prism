<?php

namespace App\Transip;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the TransIP REST API v6.
 *
 * Authentication works by POSTing a JSON body to /auth that is signed with the
 * account's RSA private key (SHA512). The resulting JWT is then sent as a
 * Bearer token on every subsequent request. Tokens are cached in-memory for
 * the lifetime of the request to avoid re-authenticating per API call.
 *
 * @see https://api.transip.nl/rest/docs.html
 */
class TransipService
{
    private const BASE_URL = 'https://api.transip.nl/v6';

    public const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SRV', 'SSHFP', 'TLSA', 'CAA', 'NAPTR'];

    /** @var array<string, string> cached JWTs keyed by account key */
    private array $tokenCache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TransipConfigLoader $configLoader,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, login: string, read_only: bool}>
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'login' => $account->login,
                'read_only' => $account->readOnly,
            ];
        }

        return $accounts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDomains(?string $accountKey = null): array
    {
        $data = $this->request($accountKey, 'GET', '/domains');

        return array_values($data['domains'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDomain(string $domainName, ?string $accountKey = null): array
    {
        $domainName = $this->normalizeDomain($domainName);
        $data = $this->request($accountKey, 'GET', sprintf('/domains/%s?include=nameservers,contacts', rawurlencode($domainName)));

        return $data['domain'] ?? [];
    }

    /**
     * @return list<array{name: string, expire: int, type: string, content: string}>
     */
    public function getDnsEntries(string $domainName, ?string $accountKey = null): array
    {
        $domainName = $this->normalizeDomain($domainName);
        $data = $this->request($accountKey, 'GET', sprintf('/domains/%s/dns', rawurlencode($domainName)));

        return array_values($data['dnsEntries'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getNameservers(string $domainName, ?string $accountKey = null): array
    {
        $domainName = $this->normalizeDomain($domainName);
        $data = $this->request($accountKey, 'GET', sprintf('/domains/%s/nameservers', rawurlencode($domainName)));

        return array_values($data['nameservers'] ?? []);
    }

    /**
     * Create or update a single DNS record.
     *
     * Matching is done on (name, type). The whole zone is never replaced, so
     * other records are left untouched.
     *  - no existing (name, type) record         → the record is added
     *  - exactly one existing (name, type) record → it is updated
     *  - multiple existing (name, type) records   → $replaceContent must be
     *    supplied to identify which one to update
     *
     * @return array{action: string, entry: array{name: string, expire: int, type: string, content: string}}
     */
    public function upsertDnsEntry(
        string $domainName,
        string $name,
        string $type,
        string $content,
        int $expire = 3600,
        ?string $replaceContent = null,
        ?string $accountKey = null,
    ): array {
        $domainName = $this->normalizeDomain($domainName);
        $type = strtoupper($type);
        $this->assertValidType($type);

        $desired = ['name' => $name, 'expire' => $expire, 'type' => $type, 'content' => $content];

        $entries = $this->getDnsEntries($domainName, $accountKey);

        // Already present exactly as desired → idempotent no-op.
        foreach ($entries as $entry) {
            if ($this->entriesEqual($entry, $desired)) {
                return ['action' => 'unchanged', 'entry' => $desired];
            }
        }

        $sameNameType = array_values(array_filter(
            $entries,
            static fn(array $e): bool => ($e['name'] ?? null) === $name && strtoupper((string) ($e['type'] ?? '')) === $type,
        ));

        if ($replaceContent !== null) {
            $sameNameType = array_values(array_filter(
                $sameNameType,
                static fn(array $e): bool => (string) ($e['content'] ?? '') === $replaceContent,
            ));
        }

        if (count($sameNameType) === 0) {
            $this->request($accountKey, 'POST', sprintf('/domains/%s/dns', rawurlencode($domainName)), [
                'dnsEntry' => $desired,
            ]);

            return ['action' => 'created', 'entry' => $desired];
        }

        if (count($sameNameType) > 1) {
            throw new \RuntimeException(sprintf(
                'Multiple "%s %s" records exist for %s. Provide replace_content to choose which one to update. Existing contents: %s',
                $name,
                $type,
                $domainName,
                implode(', ', array_map(static fn(array $e): string => (string) ($e['content'] ?? ''), $sameNameType)),
            ));
        }

        $existing = $sameNameType[0];

        // Content-only change with the same TTL can use PATCH (atomic).
        if ((int) ($existing['expire'] ?? 0) === $expire) {
            $this->request($accountKey, 'PATCH', sprintf('/domains/%s/dns', rawurlencode($domainName)), [
                'dnsEntry' => $desired,
            ]);

            return ['action' => 'updated', 'entry' => $desired];
        }

        // TTL change: delete the old entry, then add the new one.
        $this->request($accountKey, 'DELETE', sprintf('/domains/%s/dns', rawurlencode($domainName)), [
            'dnsEntry' => [
                'name' => $existing['name'],
                'expire' => (int) $existing['expire'],
                'type' => strtoupper((string) $existing['type']),
                'content' => (string) $existing['content'],
            ],
        ]);
        $this->request($accountKey, 'POST', sprintf('/domains/%s/dns', rawurlencode($domainName)), [
            'dnsEntry' => $desired,
        ]);

        return ['action' => 'updated', 'entry' => $desired];
    }

    /**
     * Delete a single DNS record. The record is identified by (name, type),
     * optionally narrowed by content/expire. If the selection is ambiguous,
     * an exception is thrown listing the candidates.
     *
     * @return array{action: string, deleted: array{name: string, expire: int, type: string, content: string}}
     */
    public function deleteDnsEntry(
        string $domainName,
        string $name,
        string $type,
        ?string $content = null,
        ?int $expire = null,
        ?string $accountKey = null,
    ): array {
        $domainName = $this->normalizeDomain($domainName);
        $type = strtoupper($type);
        $this->assertValidType($type);

        $entries = $this->getDnsEntries($domainName, $accountKey);

        $matches = array_values(array_filter($entries, function (array $e) use ($name, $type, $content, $expire): bool {
            if (($e['name'] ?? null) !== $name) {
                return false;
            }
            if (strtoupper((string) ($e['type'] ?? '')) !== $type) {
                return false;
            }
            if ($content !== null && (string) ($e['content'] ?? '') !== $content) {
                return false;
            }
            if ($expire !== null && (int) ($e['expire'] ?? 0) !== $expire) {
                return false;
            }

            return true;
        }));

        if (count($matches) === 0) {
            throw new \RuntimeException(sprintf(
                'No "%s %s" record found for %s matching the given criteria.',
                $name,
                $type,
                $domainName,
            ));
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                'Multiple "%s %s" records match for %s. Narrow down with content/expire. Candidates: %s',
                $name,
                $type,
                $domainName,
                implode(' | ', array_map(
                    static fn(array $e): string => sprintf('content=%s expire=%s', $e['content'] ?? '', $e['expire'] ?? ''),
                    $matches,
                )),
            ));
        }

        $entry = $matches[0];
        $deleted = [
            'name' => (string) $entry['name'],
            'expire' => (int) $entry['expire'],
            'type' => strtoupper((string) $entry['type']),
            'content' => (string) $entry['content'],
        ];

        $this->request($accountKey, 'DELETE', sprintf('/domains/%s/dns', rawurlencode($domainName)), [
            'dnsEntry' => $deleted,
        ]);

        return ['action' => 'deleted', 'deleted' => $deleted];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInvoices(?string $accountKey = null): array
    {
        $data = $this->request($accountKey, 'GET', '/invoices');

        return array_values($data['invoices'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(string $invoiceNumber, bool $withItems = false, ?string $accountKey = null): array
    {
        $invoice = $this->request($accountKey, 'GET', sprintf('/invoices/%s', rawurlencode($invoiceNumber)));
        $result = $invoice['invoice'] ?? [];

        if ($withItems) {
            $items = $this->request($accountKey, 'GET', sprintf('/invoices/%s/invoice-items', rawurlencode($invoiceNumber)));
            $result['invoiceItems'] = array_values($items['invoiceItems'] ?? []);
        }

        return $result;
    }

    /**
     * @param array{name?: mixed, expire?: mixed, type?: mixed, content?: mixed} $entry
     * @param array{name: string, expire: int, type: string, content: string}    $desired
     */
    private function entriesEqual(array $entry, array $desired): bool
    {
        return ($entry['name'] ?? null) === $desired['name']
            && (int) ($entry['expire'] ?? 0) === $desired['expire']
            && strtoupper((string) ($entry['type'] ?? '')) === $desired['type']
            && (string) ($entry['content'] ?? '') === $desired['content'];
    }

    private function assertValidType(string $type): void
    {
        if (!in_array($type, self::RECORD_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid DNS record type "%s". Allowed: %s',
                $type,
                implode(', ', self::RECORD_TYPES),
            ));
        }
    }

    private function normalizeDomain(string $domainName): string
    {
        $domainName = trim($domainName);
        if ($domainName === '') {
            throw new \InvalidArgumentException('Domain name is required');
        }

        return rtrim($domainName, '.');
    }

    private function resolveAccount(?string $accountKey): TransipAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No TransIP accounts configured for this server');
        }

        return reset($accounts);
    }

    private function getToken(TransipAccountConfig $account): string
    {
        if (isset($this->tokenCache[$account->key])) {
            return $this->tokenCache[$account->key];
        }

        if ($account->login === '' || trim($account->privateKey) === '') {
            throw new \RuntimeException(sprintf(
                'TransIP account "%s" is missing login or private_key',
                $account->key,
            ));
        }

        $privateKey = openssl_pkey_get_private($account->privateKey);
        if ($privateKey === false) {
            throw new \RuntimeException(sprintf(
                'TransIP account "%s" has an invalid RSA private key (%s)',
                $account->key,
                openssl_error_string() ?: 'unknown error',
            ));
        }

        $payload = [
            'login' => $account->login,
            'nonce' => bin2hex(random_bytes(12)),
            'read_only' => $account->readOnly,
            'expiration_time' => '30 minutes',
            'label' => 'prism-' . gmdate('Y-m-d\TH:i:s\Z'),
            'global_key' => $account->globalKey,
        ];

        // The signature must cover the exact byte string we transmit, so we
        // serialize once and send that same string as the request body.
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $signature = '';
        if (!openssl_sign($body, $signature, $privateKey, OPENSSL_ALGO_SHA512)) {
            throw new \RuntimeException(sprintf(
                'Failed to sign TransIP auth request: %s',
                openssl_error_string() ?: 'unknown error',
            ));
        }

        $response = $this->httpClient->request('POST', self::BASE_URL . '/auth', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Signature' => base64_encode($signature),
            ],
            'body' => $body,
            'timeout' => 30,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $err = $response->toArray(false)['error'] ?? $response->getContent(false);
            throw new \RuntimeException(sprintf('TransIP authentication failed (HTTP %d): %s', $status, $err));
        }

        $token = $response->toArray(false)['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('TransIP authentication did not return a token');
        }

        return $this->tokenCache[$account->key] = $token;
    }

    /**
     * @param array<string, mixed> $json optional JSON request body
     *
     * @return array<string, mixed>
     */
    private function request(?string $accountKey, string $method, string $path, array $json = []): array
    {
        $account = $this->resolveAccount($accountKey);
        $token = $this->getToken($account);

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($json !== []) {
            $options['json'] = $json;
        }

        $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $error = $response->toArray(false)['error'] ?? $response->getContent(false);
            throw new \RuntimeException(sprintf('TransIP API error (HTTP %d): %s', $status, $error));
        }

        // 201/204 responses (writes) carry no body.
        $content = $response->getContent(false);
        if (trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
