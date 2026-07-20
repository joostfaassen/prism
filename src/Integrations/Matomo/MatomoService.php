<?php

namespace App\Integrations\Matomo;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class MatomoService
{
    public function __construct(
        private readonly MatomoConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, base_url: string, default_id_site: int|null}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'base_url' => $profile->baseUrl,
                'default_id_site' => $profile->defaultIdSite,
            ];
        }

        return $profiles;
    }

    /**
     * List the websites this token has at least view access to.
     *
     * @return list<array<string, mixed>>
     */
    public function listSites(?string $profileKey = null): array
    {
        $data = $this->request($profileKey, 'SitesManager.getSitesWithAtLeastViewAccess');

        if (!is_array($data)) {
            return [];
        }

        $sites = [];
        foreach ($data as $site) {
            if (!is_array($site)) {
                continue;
            }

            $sites[] = [
                'idsite' => isset($site['idsite']) ? (int) $site['idsite'] : null,
                'name' => $site['name'] ?? null,
                'main_url' => $site['main_url'] ?? null,
                'timezone' => $site['timezone'] ?? null,
                'currency' => $site['currency'] ?? null,
                'type' => $site['type'] ?? null,
                'ts_created' => $site['ts_created'] ?? null,
            ];
        }

        return $sites;
    }

    /**
     * Aggregated visit metrics (visits, unique visitors, pageviews, bounce rate, ...).
     *
     * @return array<string, mixed>
     */
    public function getVisitsSummary(
        ?string $profileKey,
        ?int $idSite,
        string $period = 'day',
        string $date = 'today',
        ?string $segment = null,
    ): array {
        $data = $this->request($profileKey, 'VisitsSummary.get', [
            'idSite' => $this->resolveIdSite($profileKey, $idSite),
            'period' => $period,
            'date' => $date,
            'segment' => $segment,
        ]);

        return is_array($data) ? $data : ['value' => $data];
    }

    /**
     * Most visited page URLs for a site/period.
     *
     * @return list<array<string, mixed>>
     */
    public function getTopPageUrls(
        ?string $profileKey,
        ?int $idSite,
        string $period = 'day',
        string $date = 'today',
        int $limit = 25,
        ?string $segment = null,
    ): array {
        $data = $this->request($profileKey, 'Actions.getPageUrls', [
            'idSite' => $this->resolveIdSite($profileKey, $idSite),
            'period' => $period,
            'date' => $date,
            'segment' => $segment,
            'flat' => 1,
            'filter_limit' => $limit,
        ]);

        if (!is_array($data)) {
            return [];
        }

        $pages = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            $pages[] = [
                'label' => $row['label'] ?? null,
                'url' => $row['url'] ?? null,
                'nb_visits' => $row['nb_visits'] ?? null,
                'nb_hits' => $row['nb_hits'] ?? null,
                'sum_time_spent' => $row['sum_time_spent'] ?? null,
                'bounce_rate' => $row['bounce_rate'] ?? null,
                'exit_rate' => $row['exit_rate'] ?? null,
            ];
        }

        return $pages;
    }

    /**
     * Generic Matomo Reporting API call. Lets callers run any read report
     * method, e.g. "Referrers.getReferrerType" or "VisitTime.getVisitInformationPerServerTime".
     *
     * @param array<string, scalar|null> $params Extra parameters merged into the request
     *
     * @return mixed Decoded JSON response (array or scalar)
     */
    public function getReport(
        ?string $profileKey,
        string $method,
        ?int $idSite,
        string $period = 'day',
        string $date = 'today',
        ?string $segment = null,
        array $params = [],
    ): mixed {
        return $this->request($profileKey, $method, array_merge([
            'idSite' => $this->resolveIdSite($profileKey, $idSite),
            'period' => $period,
            'date' => $date,
            'segment' => $segment,
        ], $params));
    }

    private function resolveIdSite(?string $profileKey, ?int $idSite): int
    {
        if ($idSite !== null) {
            return $idSite;
        }

        $default = $this->resolveProfile($profileKey)->defaultIdSite;
        if ($default !== null) {
            return $default;
        }

        throw new \InvalidArgumentException(
            'No idSite provided and no default_id_site configured for this Matomo profile. '
            . 'Use matomo_list_sites to discover available site IDs.',
        );
    }

    private function resolveProfile(?string $profileKey): MatomoProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No Matomo profiles configured for this server');
        }

        return reset($profiles);
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return mixed
     */
    private function request(?string $profileKey, string $method, array $params = []): mixed
    {
        $profile = $this->resolveProfile($profileKey);

        if ($profile->baseUrl === '' || $profile->tokenAuth === '') {
            throw new \RuntimeException(sprintf(
                'Matomo profile "%s" is missing base_url or token_auth',
                $profile->key,
            ));
        }

        $body = [
            'module' => 'API',
            'method' => $method,
            'format' => 'json',
            'token_auth' => $profile->tokenAuth,
        ];

        foreach ($params as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $body[$name] = $value;
        }

        $response = $this->httpClient->request('POST', $profile->baseUrl . '/index.php', [
            'body' => $body,
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Matomo API error (HTTP %d): %s',
                $statusCode,
                $response->getContent(false),
            ));
        }

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (is_array($data) && ($data['result'] ?? null) === 'error') {
            throw new \RuntimeException(sprintf(
                'Matomo API error: %s',
                $data['message'] ?? 'Unknown error',
            ));
        }

        return $data;
    }
}
