<?php

namespace App\SendGrid;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SendGridService
{
    public function __construct(
        private readonly SendGridConfigLoader $configLoader,
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
     * Global email statistics over a date range (requests, delivered, opens,
     * unique_opens, clicks, unique_clicks, unsubscribes, bounces, spam_reports,
     * blocks, ...). Optionally time-bucketed by day/week/month.
     *
     * @return mixed
     */
    public function getGlobalStats(
        ?string $accountKey,
        string $startDate,
        ?string $endDate = null,
        ?string $aggregatedBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): mixed {
        return $this->request($accountKey, 'GET', '/v3/stats', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'aggregated_by' => $aggregatedBy,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Per-category email statistics over a date range. Useful to see which
     * categories (often used to tag emails by template/campaign) drive opens
     * and clicks. Up to 10 categories.
     *
     * @param list<string> $categories
     *
     * @return mixed
     */
    public function getCategoryStats(
        ?string $accountKey,
        string $startDate,
        array $categories,
        ?string $endDate = null,
        ?string $aggregatedBy = null,
    ): mixed {
        return $this->request($accountKey, 'GET', '/v3/categories/stats', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'categories' => $categories,
            'aggregated_by' => $aggregatedBy,
        ]);
    }

    /**
     * Summed email statistics per category over a date range, sortable by a
     * metric. Best tool to rank which categories (templates) generate the most
     * clicks, opens, etc.
     *
     * @return mixed
     */
    public function getCategoryStatsSums(
        ?string $accountKey,
        string $startDate,
        ?string $endDate = null,
        ?string $sortByMetric = null,
        ?string $sortByDirection = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $aggregatedBy = null,
    ): mixed {
        return $this->request($accountKey, 'GET', '/v3/categories/stats/sums', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'sort_by_metric' => $sortByMetric,
            'sort_by_direction' => $sortByDirection,
            'limit' => $limit,
            'offset' => $offset,
            'aggregated_by' => $aggregatedBy,
        ]);
    }

    /**
     * List Marketing Campaigns Single Sends (newsletters/campaigns). Use this
     * to map a Single Send name to its id before fetching its stats.
     *
     * @return mixed
     */
    public function listSingleSends(
        ?string $accountKey,
        ?int $pageSize = null,
    ): mixed {
        return $this->request($accountKey, 'GET', '/v3/marketing/singlesends', [
            'page_size' => $pageSize,
        ]);
    }

    /**
     * Marketing Campaigns Single Send statistics. With no singleSendId, returns
     * stats for all Single Sends; with one, returns that Single Send's stats.
     * Reveals which campaigns/templates generate opens and clicks.
     *
     * @return mixed
     */
    public function getSingleSendStats(
        ?string $accountKey,
        ?string $singleSendId = null,
        ?string $aggregatedBy = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $pageSize = null,
    ): mixed {
        $path = ($singleSendId !== null && $singleSendId !== '')
            ? '/v3/marketing/stats/singlesends/' . rawurlencode($singleSendId)
            : '/v3/marketing/stats/singlesends';

        return $this->request($accountKey, 'GET', $path, [
            'aggregated_by' => $aggregatedBy,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * Generic read-only GET against any SendGrid v3 endpoint, for flexible
     * ad-hoc queries not covered by the dedicated tools.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     *
     * @return mixed
     */
    public function get(?string $accountKey, string $path, array $query = []): mixed
    {
        return $this->request($accountKey, 'GET', $path, $query);
    }

    private function resolveAccount(?string $accountKey): SendGridAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No SendGrid accounts configured for this server');
        }

        return reset($accounts);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     *
     * @return mixed
     */
    private function request(?string $accountKey, string $method, string $path, array $query = []): mixed
    {
        $account = $this->resolveAccount($accountKey);

        if ($account->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'SendGrid account "%s" is missing api_key',
                $account->key,
            ));
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $url = $account->baseUrl . $path;
        $queryString = $this->buildQueryString($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $response = $this->httpClient->request($method, $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $account->apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'SendGrid API error (HTTP %d): %s',
                $statusCode,
                $response->getContent(false),
            ));
        }

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Build a query string that supports repeated keys (e.g. categories=a&categories=b),
     * which SendGrid expects instead of PHP's default categories[0]=a notation.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     */
    private function buildQueryString(array $query): string
    {
        $parts = [];

        foreach ($query as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item === null || $item === '') {
                        continue;
                    }
                    $parts[] = rawurlencode($name) . '=' . rawurlencode((string) $item);
                }

                continue;
            }

            $normalized = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $parts[] = rawurlencode($name) . '=' . rawurlencode($normalized);
        }

        return implode('&', $parts);
    }
}
