<?php

namespace App\Integrations\Prometheus;

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
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'base_url' => $profile->baseUrl,
            ];
        }

        return $profiles;
    }

    /**
     * Run an instant PromQL query (GET/POST /api/v1/query).
     *
     * @return array<string, mixed>
     */
    public function query(?string $profileKey, string $query, ?string $time = null): array
    {
        $params = ['query' => $query];
        if ($time !== null && $time !== '') {
            $params['time'] = $time;
        }

        return $this->request($profileKey, 'POST', '/api/v1/query', $params);
    }

    /**
     * Run a range PromQL query (GET/POST /api/v1/query_range).
     *
     * @return array<string, mixed>
     */
    public function queryRange(
        ?string $profileKey,
        string $query,
        string $start,
        string $end,
        string $step,
    ): array {
        return $this->request($profileKey, 'POST', '/api/v1/query_range', [
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
    public function listAlerts(?string $profileKey): array
    {
        return $this->request($profileKey, 'GET', '/api/v1/alerts');
    }

    /**
     * Alerting and/or recording rules (GET /api/v1/rules).
     *
     * @return array<string, mixed>
     */
    public function listRules(?string $profileKey, ?string $type = null): array
    {
        $params = [];
        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }

        return $this->request($profileKey, 'GET', '/api/v1/rules', $params);
    }

    /**
     * Scrape target states (GET /api/v1/targets).
     *
     * @return array<string, mixed>
     */
    public function listTargets(?string $profileKey, ?string $state = null): array
    {
        $params = [];
        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }

        return $this->request($profileKey, 'GET', '/api/v1/targets', $params);
    }

    /**
     * List known metric names (GET /api/v1/label/__name__/values).
     *
     * @return list<string>
     */
    public function listMetricNames(?string $profileKey): array
    {
        return $this->labelValues($profileKey, '__name__');
    }

    /**
     * List the values of a label (GET /api/v1/label/{name}/values).
     *
     * @param list<string> $match Optional series selectors, e.g. ["up", "node_cpu_seconds_total"]
     *
     * @return list<string>
     */
    public function labelValues(?string $profileKey, string $label, array $match = []): array
    {
        $params = [];
        if ($match !== []) {
            $params['match[]'] = $match;
        }

        $data = $this->request($profileKey, 'GET', '/api/v1/label/' . rawurlencode($label) . '/values', $params);
        $values = $data['data'] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private function resolveProfile(?string $profileKey): PrometheusProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No Prometheus profiles configured for this server');
        }

        return reset($profiles);
    }

    /**
     * @param array<string, scalar|list<string>> $params
     *
     * @return array<string, mixed>
     */
    private function request(?string $profileKey, string $method, string $path, array $params = []): array
    {
        $profile = $this->resolveProfile($profileKey);

        if ($profile->baseUrl === '') {
            throw new \RuntimeException(sprintf(
                'Prometheus profile "%s" is missing base_url',
                $profile->key,
            ));
        }

        $options = ['timeout' => 30];

        if ($profile->bearerToken !== '') {
            $options['auth_bearer'] = $profile->bearerToken;
        } elseif ($profile->username !== '') {
            $options['auth_basic'] = [$profile->username, $profile->password];
        }

        if ($method === 'GET') {
            $options['query'] = $params;
        } else {
            $options['body'] = $params;
        }

        $response = $this->httpClient->request($method, $profile->baseUrl . $path, $options);

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
