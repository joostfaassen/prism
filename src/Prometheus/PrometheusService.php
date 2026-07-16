<?php

namespace App\Prometheus;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PrometheusService
{
    public function __construct(
        private readonly PrometheusConfigLoader $configLoader,
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
     * Run an instant PromQL query (GET/POST /api/v1/query).
     *
     * @return array<string, mixed>
     */
    public function query(?string $accountKey, string $query, ?string $time = null): array
    {
        $params = ['query' => $query];
        if ($time !== null && $time !== '') {
            $params['time'] = $time;
        }

        return $this->request($accountKey, 'POST', '/api/v1/query', $params);
    }

    /**
     * Run a range PromQL query (GET/POST /api/v1/query_range).
     *
     * @return array<string, mixed>
     */
    public function queryRange(
        ?string $accountKey,
        string $query,
        string $start,
        string $end,
        string $step,
    ): array {
        return $this->request($accountKey, 'POST', '/api/v1/query_range', [
            'query' => $query,
            'start' => $start,
            'end' => $end,
            'step' => $step,
        ]);
    }

    /**
     * Active alerts as seen by Prometheus (GET /api/v1/alerts).
     *
     * @return array<string, mixed>
     */
    public function listAlerts(?string $accountKey): array
    {
        return $this->request($accountKey, 'GET', '/api/v1/alerts');
    }

    /**
     * Alerting and/or recording rules (GET /api/v1/rules).
     *
     * @return array<string, mixed>
     */
    public function listRules(?string $accountKey, ?string $type = null): array
    {
        $params = [];
        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }

        return $this->request($accountKey, 'GET', '/api/v1/rules', $params);
    }

    /**
     * Scrape target states (GET /api/v1/targets).
     *
     * @return array<string, mixed>
     */
    public function listTargets(?string $accountKey, ?string $state = null): array
    {
        $params = [];
        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }

        return $this->request($accountKey, 'GET', '/api/v1/targets', $params);
    }

    /**
     * List known metric names (GET /api/v1/label/__name__/values).
     *
     * @return list<string>
     */
    public function listMetricNames(?string $accountKey): array
    {
        return $this->labelValues($accountKey, '__name__');
    }

    /**
     * List the values of a label (GET /api/v1/label/{name}/values).
     *
     * @param list<string> $match Optional series selectors, e.g. ["up", "node_cpu_seconds_total"]
     *
     * @return list<string>
     */
    public function labelValues(?string $accountKey, string $label, array $match = []): array
    {
        $params = [];
        if ($match !== []) {
            $params['match[]'] = $match;
        }

        $data = $this->request($accountKey, 'GET', '/api/v1/label/' . rawurlencode($label) . '/values', $params);
        $values = $data['data'] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private function resolveAccount(?string $accountKey): PrometheusAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No Prometheus accounts configured for this server');
        }

        return reset($accounts);
    }

    /**
     * @param array<string, scalar|list<string>> $params
     *
     * @return array<string, mixed>
     */
    private function request(?string $accountKey, string $method, string $path, array $params = []): array
    {
        $account = $this->resolveAccount($accountKey);

        if ($account->baseUrl === '') {
            throw new \RuntimeException(sprintf(
                'Prometheus account "%s" is missing base_url',
                $account->key,
            ));
        }

        $options = ['timeout' => 30];

        if ($account->bearerToken !== '') {
            $options['auth_bearer'] = $account->bearerToken;
        } elseif ($account->username !== '') {
            $options['auth_basic'] = [$account->username, $account->password];
        }

        if ($method === 'GET') {
            $options['query'] = $params;
        } else {
            $options['body'] = $params;
        }

        $response = $this->httpClient->request($method, $account->baseUrl . $path, $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $data = $content !== '' ? json_decode($content, true) : null;

        if (!is_array($data)) {
            if ($statusCode >= 400) {
                throw new \RuntimeException(sprintf(
                    'Prometheus API error (HTTP %d): %s',
                    $statusCode,
                    $content,
                ));
            }

            throw new \RuntimeException('Prometheus API returned an unexpected non-JSON response');
        }

        if (($data['status'] ?? null) === 'error') {
            throw new \RuntimeException(sprintf(
                'Prometheus API error (%s): %s',
                $data['errorType'] ?? 'unknown',
                $data['error'] ?? 'Unknown error',
            ));
        }

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Prometheus API error (HTTP %d): %s',
                $statusCode,
                $content,
            ));
        }

        return $data;
    }
}
