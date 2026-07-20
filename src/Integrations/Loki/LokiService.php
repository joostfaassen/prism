<?php

namespace App\Integrations\Loki;

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
     * Range LogQL query (GET /loki/api/v1/query_range).
     *
     * @return array<string, mixed>
     */
    public function queryRange(
        ?string $profileKey,
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

        return $this->request($profileKey, '/loki/api/v1/query_range', $params);
    }

    /**
     * Instant LogQL query (GET /loki/api/v1/query).
     *
     * @return array<string, mixed>
     */
    public function query(
        ?string $profileKey,
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

        return $this->request($profileKey, '/loki/api/v1/query', $params);
    }

    /**
     * List label names (GET /loki/api/v1/labels).
     *
     * @return list<string>
     */
    public function listLabels(?string $profileKey, ?string $start = null, ?string $end = null): array
    {
        $params = [];
        if ($start !== null && $start !== '') {
            $params['start'] = $start;
        }
        if ($end !== null && $end !== '') {
            $params['end'] = $end;
        }

        $data = $this->request($profileKey, '/loki/api/v1/labels', $params);
        $values = $data['data'] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /**
     * List the values of a label (GET /loki/api/v1/label/{name}/values).
     *
     * @return list<string>
     */
    public function labelValues(
        ?string $profileKey,
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

        $data = $this->request($profileKey, '/loki/api/v1/label/' . rawurlencode($label) . '/values', $params);
        $values = $data['data'] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    private function resolveProfile(?string $profileKey): LokiProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No Loki profiles configured for this server');
        }

        return reset($profiles);
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed>
     */
    private function request(?string $profileKey, string $path, array $params = []): array
    {
        $profile = $this->resolveProfile($profileKey);

        if ($profile->baseUrl === '') {
            throw new \RuntimeException(sprintf(
                'Loki profile "%s" is missing base_url',
                $profile->key,
            ));
        }

        $options = ['timeout' => 30];
        if ($params !== []) {
            $options['query'] = $params;
        }

        $headers = [];
        if ($profile->orgId !== '') {
            $headers['X-Scope-OrgID'] = $profile->orgId;
        }
        if ($headers !== []) {
            $options['headers'] = $headers;
        }

        if ($profile->bearerToken !== '') {
            $options['auth_bearer'] = $profile->bearerToken;
        } elseif ($profile->username !== '') {
            $options['auth_basic'] = [$profile->username, $profile->password];
        }

        $response = $this->httpClient->request('GET', $profile->baseUrl . $path, $options);

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
