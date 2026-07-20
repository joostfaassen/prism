<?php

namespace App\Integrations\Alertmanager;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AlertmanagerService
{
    public function __construct(
        private readonly AlertmanagerConfigLoader $configLoader,
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
     * List alerts (GET /api/v2/alerts).
     *
     * @param list<string> $filters Label matchers, e.g. ["severity=critical", "job=~api.*"]
     *
     * @return list<array<string, mixed>>
     */
    public function listAlerts(
        ?string $profileKey,
        bool $active = true,
        bool $silenced = false,
        bool $inhibited = false,
        array $filters = [],
    ): array {
        $query = [
            'active' => $active ? 'true' : 'false',
            'silenced' => $silenced ? 'true' : 'false',
            'inhibited' => $inhibited ? 'true' : 'false',
        ];

        if ($filters !== []) {
            $query['filter'] = $filters;
        }

        $data = $this->request($profileKey, 'GET', '/api/v2/alerts', $query);

        return is_array($data) ? $data : [];
    }

    /**
     * List silences (GET /api/v2/silences).
     *
     * @param list<string> $filters Label matchers, e.g. ["alertname=HighCpu"]
     *
     * @return list<array<string, mixed>>
     */
    public function listSilences(?string $profileKey, array $filters = []): array
    {
        $query = [];
        if ($filters !== []) {
            $query['filter'] = $filters;
        }

        $data = $this->request($profileKey, 'GET', '/api/v2/silences', $query);

        return is_array($data) ? $data : [];
    }

    /**
     * Alert groups as routed by Alertmanager (GET /api/v2/alerts/groups).
     *
     * @param list<string> $filters Label matchers, e.g. ["severity=critical"]
     *
     * @return list<array<string, mixed>>
     */
    public function listAlertGroups(
        ?string $profileKey,
        bool $active = true,
        bool $silenced = false,
        bool $inhibited = false,
        array $filters = [],
    ): array {
        $query = [
            'active' => $active ? 'true' : 'false',
            'silenced' => $silenced ? 'true' : 'false',
            'inhibited' => $inhibited ? 'true' : 'false',
        ];

        if ($filters !== []) {
            $query['filter'] = $filters;
        }

        $data = $this->request($profileKey, 'GET', '/api/v2/alerts/groups', $query);

        return is_array($data) ? $data : [];
    }

    /**
     * Alertmanager status: version, cluster, uptime (GET /api/v2/status).
     *
     * @return array<string, mixed>
     */
    public function getStatus(?string $profileKey): array
    {
        $data = $this->request($profileKey, 'GET', '/api/v2/status');

        return is_array($data) ? $data : [];
    }

    /**
     * Create (or update) a silence (POST /api/v2/silences).
     *
     * @param list<array{name: string, value: string, isRegex?: bool, isEqual?: bool}> $matchers
     *
     * @return array<string, mixed> The created silence, including its silenceID
     */
    public function createSilence(
        ?string $profileKey,
        array $matchers,
        string $startsAt,
        string $endsAt,
        string $createdBy,
        string $comment,
    ): array {
        $body = [
            'matchers' => array_map(static function (array $m): array {
                return [
                    'name' => (string) $m['name'],
                    'value' => (string) $m['value'],
                    'isRegex' => (bool) ($m['isRegex'] ?? false),
                    'isEqual' => (bool) ($m['isEqual'] ?? true),
                ];
            }, $matchers),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'createdBy' => $createdBy,
            'comment' => $comment,
        ];

        $data = $this->request($profileKey, 'POST', '/api/v2/silences', [], $body);

        return is_array($data) ? $data : [];
    }

    /**
     * Expire (delete) a silence (DELETE /api/v2/silence/{silenceID}).
     */
    public function expireSilence(?string $profileKey, string $silenceId): void
    {
        $this->request($profileKey, 'DELETE', '/api/v2/silence/' . rawurlencode($silenceId));
    }

    private function resolveProfile(?string $profileKey): AlertmanagerProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No Alertmanager profiles configured for this server');
        }

        return reset($profiles);
    }

    /**
     * @param array<string, scalar|list<string>> $query
     * @param array<string, mixed>|null          $json
     *
     * @return mixed Decoded JSON response
     */
    private function request(?string $profileKey, string $method, string $path, array $query = [], ?array $json = null): mixed
    {
        $profile = $this->resolveProfile($profileKey);

        if ($profile->baseUrl === '') {
            throw new \RuntimeException(sprintf(
                'Alertmanager profile "%s" is missing base_url',
                $profile->key,
            ));
        }

        $options = ['timeout' => 30];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }

        if ($profile->bearerToken !== '') {
            $options['auth_bearer'] = $profile->bearerToken;
        } elseif ($profile->username !== '') {
            $options['auth_basic'] = [$profile->username, $profile->password];
        }

        $response = $this->httpClient->request($method, $profile->baseUrl . $path, $options);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Alertmanager API error (HTTP %d): %s',
                $statusCode,
                $content,
            ));
        }

        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
