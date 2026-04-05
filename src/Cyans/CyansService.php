<?php

namespace App\Cyans;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CyansService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CyansConfigLoader $configLoader,
    ) {
    }

    public function getDefaultUsername(?string $accountKey = null): string
    {
        return $this->resolveAccount($accountKey)->username;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserState(string $username, ?string $accountKey = null): array
    {
        return $this->request('GET', "/users/{$username}", accountKey: $accountKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOpenTopics(string $username, ?string $accountKey = null): array
    {
        $state = $this->getUserState($username, $accountKey);
        $topics = $state['topics'] ?? [];
        $open = [];

        foreach ($topics as $id => $topic) {
            $topic['id'] = $topic['id'] ?? $id;

            if (($topic['status'] ?? '') === 'Open' || ($topic['live'] ?? false)) {
                $open[] = $topic;
            }
        }

        usort($open, function (array $a, array $b): int {
            return ($b['lastUpdatedAt'] ?? '') <=> ($a['lastUpdatedAt'] ?? '');
        });

        return $open;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTopicDetails(string $topicId, ?string $accountKey = null): array
    {
        return $this->request('GET', "/topics/{$topicId}", accountKey: $accountKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchTopics(string $username, string $query, ?string $accountKey = null): array
    {
        $state = $this->getUserState($username, $accountKey);
        $topics = $state['topics'] ?? [];
        $queryLower = mb_strtolower($query);
        $results = [];

        foreach ($topics as $id => $topic) {
            $topic['id'] = $topic['id'] ?? $id;
            $subject = mb_strtolower($topic['subject'] ?? '');

            if (str_contains($subject, $queryLower)) {
                $results[] = $topic;
            }
        }

        usort($results, function (array $a, array $b): int {
            return ($b['lastUpdatedAt'] ?? '') <=> ($a['lastUpdatedAt'] ?? '');
        });

        return $results;
    }

    /**
     * @return array{status: string, message: string}
     */
    public function addPost(string $topicId, string $message, ?string $author = null, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $author ??= $account->username;

        return $this->request('POST', "/topics/{$topicId}/add-post", [
            'json' => [
                'author' => $author,
                'message' => $message,
            ],
        ], $accountKey);
    }

    private function resolveAccount(?string $accountKey): CyansAccountConfig
    {
        if ($accountKey !== null) {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No Cyans accounts configured');
        }

        return reset($accounts);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = [], ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $parsed = parse_url($account->dsn);

        if ($parsed === false || !isset($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid Cyans DSN: cannot parse URL');
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $baseUrl = "{$scheme}://{$host}{$port}/api/v1";
        $authUser = $parsed['user'] ?? '';
        $authPassword = $parsed['pass'] ?? '';

        $response = $this->httpClient->request($method, $baseUrl . $path, array_merge([
            'auth_basic' => [$authUser, $authPassword],
        ], $options));

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if (isset($data['error'])) {
            throw new \RuntimeException(sprintf(
                'Cyans API error (%d): %s',
                $data['error']['code'] ?? $statusCode,
                $data['error']['message'] ?? 'Unknown error',
            ));
        }

        return $data;
    }
}
