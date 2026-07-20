<?php

namespace App\Integrations\Cyans;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CyansService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CyansConfigLoader $configLoader,
    ) {
    }

    public function getDefaultUsername(?string $profileKey = null): string
    {
        return $this->resolveProfile($profileKey)->username;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserState(string $username, ?string $profileKey = null): array
    {
        return $this->request('GET', "/users/{$username}", profileKey: $profileKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOpenTopics(string $username, ?string $profileKey = null): array
    {
        $state = $this->getUserState($username, $profileKey);
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
    public function getTopicDetails(string $topicId, ?string $profileKey = null): array
    {
        return $this->request('GET', "/topics/{$topicId}", profileKey: $profileKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchTopics(string $username, string $query, ?string $profileKey = null): array
    {
        $state = $this->getUserState($username, $profileKey);
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
    public function addPost(string $topicId, string $message, ?string $author = null, ?string $profileKey = null): array
    {
        $profile = $this->resolveProfile($profileKey);
        $author ??= $profile->username;

        return $this->request('POST', "/topics/{$topicId}/add-post", [
            'json' => [
                'author' => $author,
                'message' => $message,
            ],
        ], $profileKey);
    }

    private function resolveProfile(?string $profileKey): CyansProfileConfig
    {
        if ($profileKey !== null) {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No Cyans profiles configured');
        }

        return reset($profiles);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = [], ?string $profileKey = null): array
    {
        $profile = $this->resolveProfile($profileKey);
        $parsed = parse_url($profile->dsn);

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
