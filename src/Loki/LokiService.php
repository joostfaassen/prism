<?php

namespace App\Loki;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LokiService
{
    public function __construct(
        private readonly LokiConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, base_url: string}>
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'base_url' => $account->baseUrl,
            ];
        }

        return $accounts;
    }

    /**
     * Range LogQL query (GET /loki/api/v1/query_range).
     *
     * @return array<string, mixed>
     */
    public function queryRange(
        ?string $accountKey,
        string $query,
        ?string $start = null,
        ?string $end = null,
        int $limit = 100,
        string $direction = 'backward',
        ?string $step = null,
    ): array {
        $params = [
            'query' => $query,
            'limit' => $limit,
            'direction' => $direction,
        ];
        if ($start !== null && $start !== '') {
            $params['start'] = $start;
        }
        if ($end !== null && $end !== '') {
            $params['end'] = $end;
        }
        if ($step !== null && $step !== '') {
            $params['step'] = $step;
        }

        return $this->request($accountKey, '/loki/api/v1/query_range', $params);
    }

    /**
     * Instant LogQL query (GET /loki/api/v1/query).
     *
     * @return array<string, mixed>
     */
    public function query(
        ?string $accountKey,
        string $query,
        ?string $time = null,
        int $limit = 100,
        string $direction = 'backward',
    ): array {
        $params = [
            'query' => $query,
            'limit' => $limit,
            'direction' => $direction,
        ];
        if ($time !== null && $time !== '') {
            $params['time'] = $time;
        }

        return $this->request($accountKey, '/loki/api/v1/query', $params);
    }

    /**
     * List label names (GET /loki/api/v1/labels).
     *
     * @return list<string>
     */
    public function listLabels(?string $accountKey, ?string $start = null, ?string $end = null): array
    {
        $params = [];
        if ($start !== null && $start !== '') {
            $params['start'] = $start;
        }
        if ($end !== null && $end !== '') {
            $params['end'] = $end;
        }

        $data = $this->request($accountKey, '/loki/api/v1/labels', $params);
        $values = $data['data'] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /**
     * List the values of a label (GET /loki/api/v1/label/{name}/values).
     *
     * @return list<string>
     */
    public function labelValues(
        ?string $accountKey,
        string $label,
        ?string $start = null,
        ?string $end = null,
    ): array {
        $params = [];
        if ($start !== null && $start !== '') {
            $params['start'] = $start;
        }
        if ($end !== null && $end !== '') {
            $params['end'] = $end;
        }

        $data = $this->request($accountKey, '/loki/api/v1/label/' . rawurlencode($label) . '/values', $params);
        $values = $data['data'] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private function resolveAccount(?string $accountKey): LokiAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No Loki accounts configured for this server');
        }

        return reset($accounts);
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed>
     */
    private function request(?string $accountKey, string $path, array $params = []): array
    {
        $account = $this->resolveAccount($accountKey);

        if ($account->baseUrl === '') {
            throw new \RuntimeException(sprintf(
                'Loki account "%s" is missing base_url',
                $account->key,
            ));
        }

        $options = ['timeout' => 30];
        if ($params !== []) {
            $options['query'] = $params;
        }

        $headers = [];
        if ($account->orgId !== '') {
            $headers['X-Scope-OrgID'] = $account->orgId;
        }
        if ($headers !== []) {
            $options['headers'] = $headers;
        }

        if ($account->bearerToken !== '') {
            $options['auth_bearer'] = $account->bearerToken;
        } elseif ($account->username !== '') {
            $options['auth_basic'] = [$account->username, $account->password];
        }

        $response = $this->httpClient->request('GET', $account->baseUrl . $path, $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $data = $content !== '' ? json_decode($content, true) : null;

        if (!is_array($data)) {
            if ($statusCode >= 400) {
                throw new \RuntimeException(sprintf(
                    'Loki API error (HTTP %d): %s',
                    $statusCode,
                    $content,
                ));
            }

            throw new \RuntimeException('Loki API returned an unexpected non-JSON response');
        }

        if (($data['status'] ?? null) === 'error') {
            throw new \RuntimeException(sprintf(
                'Loki API error: %s',
                $data['error'] ?? $data['message'] ?? 'Unknown error',
            ));
        }

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Loki API error (HTTP %d): %s',
                $statusCode,
                $content,
            ));
        }

        return $data;
    }
}
