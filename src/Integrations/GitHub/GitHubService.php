<?php

namespace App\Integrations\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitHubService
{
    private const API_VERSION = '2022-11-28';
    private const USER_AGENT = 'prism-mcp';

    /** @var array<string, string> cache of profileKey => resolved authenticated login */
    private array $loginCache = [];

    public function __construct(
        private readonly GitHubConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, base_url: string, default_login: string|null}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'base_url' => $profile->baseUrl,
                'default_login' => $profile->defaultLogin,
            ];
        }

        return $profiles;
    }

    /**
     * Resolve the login of the token owner (GET /user), cached per profile.
     */
    public function getAuthenticatedLogin(?string $profileKey): string
    {
        $profile = $this->resolveProfile($profileKey);

        if (isset($this->loginCache[$profile->key])) {
            return $this->loginCache[$profile->key];
        }

        $data = $this->request($profile, 'GET', '/user');
        $login = is_array($data) ? (string) ($data['login'] ?? '') : '';

        if ($login === '') {
            throw new \RuntimeException('Could not resolve authenticated GitHub user for profile ' . $profile->key);
        }

        return $this->loginCache[$profile->key] = $login;
    }

    /**
     * The pulse collector: everything a user did in a timespan, in buckets.
     *
     * Uses the GitHub Search API. Dates are YYYY-MM-DD; a single day means
     * from == to (inclusive). Commits use author-date; PRs/issues use the
     * relevant qualifier per bucket (created / merged / updated).
     *
     * @return array<string, mixed>
     */
    public function getActivity(
        ?string $profileKey,
        ?string $login,
        string $from,
        string $to,
        int $limit = 100,
    ): array {
        $profile = $this->resolveProfile($profileKey);
        $login = $this->resolveLogin($profile, $login);
        $range = $from . '..' . $to;
        $limit = max(1, min(100, $limit));

        $commits = $this->searchCommits(
            $profile,
            sprintf('author:%s author-date:%s', $login, $range),
            $limit,
        );
        $prsCreated = $this->searchIssuesRaw(
            $profile,
            sprintf('is:pr author:%s created:%s', $login, $range),
            $limit,
        );
        $prsMerged = $this->searchIssuesRaw(
            $profile,
            sprintf('is:pr author:%s merged:%s', $login, $range),
            $limit,
        );
        $prsReviewed = $this->searchIssuesRaw(
            $profile,
            sprintf('is:pr reviewed-by:%s -author:%s updated:%s', $login, $login, $range),
            $limit,
        );
        $issuesCreated = $this->searchIssuesRaw(
            $profile,
            sprintf('is:issue author:%s created:%s', $login, $range),
            $limit,
        );

        $total = count($commits) + count($prsCreated) + count($prsMerged)
            + count($prsReviewed) + count($issuesCreated);

        return [
            'login' => $login,
            'from' => $from,
            'to' => $to,
            'total_events' => $total,
            'commits' => $commits,
            'prs_created' => $prsCreated,
            'prs_merged' => $prsMerged,
            'prs_reviewed' => $prsReviewed,
            'issues_created' => $issuesCreated,
        ];
    }

    /**
     * Flexible issue/PR search using GitHub search qualifiers.
     *
     * @return array<string, mixed>
     */
    public function searchIssues(
        ?string $profileKey,
        string $query,
        string $sort = 'updated',
        string $order = 'desc',
        int $limit = 30,
    ): array {
        $profile = $this->resolveProfile($profileKey);
        $limit = max(1, min(100, $limit));

        $data = $this->request($profile, 'GET', '/search/issues', [
            'q' => $query,
            'sort' => $sort,
            'order' => $order,
            'per_page' => $limit,
        ]);

        $items = is_array($data) ? ($data['items'] ?? []) : [];
        $results = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $results[] = $this->normalizeIssue($item);
            }
        }

        return [
            'query' => $query,
            'total_count' => is_array($data) ? ($data['total_count'] ?? count($results)) : count($results),
            'returned' => count($results),
            'items' => $results,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchCommits(GitHubProfileConfig $profile, string $query, int $limit): array
    {
        $data = $this->request($profile, 'GET', '/search/commits', [
            'q' => $query,
            'sort' => 'author-date',
            'order' => 'asc',
            'per_page' => $limit,
        ]);

        $items = is_array($data) ? ($data['items'] ?? []) : [];
        $results = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $commit = is_array($item['commit'] ?? null) ? $item['commit'] : [];
            $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];
            $repo = is_array($item['repository'] ?? null) ? $item['repository'] : [];
            $message = (string) ($commit['message'] ?? '');

            $results[] = [
                'sha' => $item['sha'] ?? null,
                'repo' => $repo['full_name'] ?? null,
                'message' => $this->firstLine($message),
                'date' => $author['date'] ?? null,
                'url' => $item['html_url'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchIssuesRaw(GitHubProfileConfig $profile, string $query, int $limit): array
    {
        $data = $this->request($profile, 'GET', '/search/issues', [
            'q' => $query,
            'sort' => 'updated',
            'order' => 'asc',
            'per_page' => $limit,
        ]);

        $items = is_array($data) ? ($data['items'] ?? []) : [];
        $results = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $results[] = $this->normalizeIssue($item);
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function normalizeIssue(array $item): array
    {
        $pr = is_array($item['pull_request'] ?? null) ? $item['pull_request'] : null;
        $user = is_array($item['user'] ?? null) ? $item['user'] : [];

        return [
            'repo' => $this->repoFromUrl((string) ($item['repository_url'] ?? '')),
            'number' => $item['number'] ?? null,
            'title' => $item['title'] ?? null,
            'type' => $pr !== null ? 'pull_request' : 'issue',
            'state' => $item['state'] ?? null,
            'author' => $user['login'] ?? null,
            'created_at' => $item['created_at'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'closed_at' => $item['closed_at'] ?? null,
            'merged_at' => $pr['merged_at'] ?? null,
            'url' => $item['html_url'] ?? null,
        ];
    }

    private function repoFromUrl(string $repositoryUrl): ?string
    {
        if ($repositoryUrl === '') {
            return null;
        }

        $path = parse_url($repositoryUrl, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        // /repos/owner/name → owner/name
        if (preg_match('#/repos/([^/]+/[^/]+)$#', $path, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? '' : trim($line);
    }

    private function resolveLogin(GitHubProfileConfig $profile, ?string $login): string
    {
        if ($login !== null && trim($login) !== '') {
            return trim($login);
        }

        if ($profile->defaultLogin !== null && $profile->defaultLogin !== '') {
            return $profile->defaultLogin;
        }

        return $this->getAuthenticatedLogin($profile->key);
    }

    private function resolveProfile(?string $profileKey): GitHubProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No GitHub profiles configured for this server');
        }

        return reset($profiles);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return mixed decoded JSON
     */
    private function request(GitHubProfileConfig $profile, string $method, string $path, array $query = []): mixed
    {
        if ($profile->token === '') {
            throw new \RuntimeException(sprintf('GitHub profile "%s" is missing a token', $profile->key));
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $profile->token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => self::API_VERSION,
                'User-Agent' => self::USER_AGENT,
            ],
            'timeout' => 30,
        ];

        if ($query !== []) {
            $options['query'] = array_filter(
                $query,
                static fn($v): bool => $v !== null && $v !== '',
            );
        }

        $response = $this->httpClient->request($method, $profile->baseUrl . $path, $options);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'GitHub API error (HTTP %d) on %s: %s',
                $statusCode,
                $path,
                $response->getContent(false),
            ));
        }

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
