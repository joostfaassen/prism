<?php

namespace App\Cyans;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CyansService
{
    private string $baseUrl;
    private string $authUser;
    private string $authPassword;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $cyansDsn,
        private readonly string $cyansUsername,
    ) {
        $parsed = parse_url($cyansDsn);

        if ($parsed === false || !isset($parsed['host'])) {
            throw new \InvalidArgumentException('Invalid CYANS_DSN: cannot parse URL');
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $this->baseUrl = "{$scheme}://{$host}{$port}/api/v1";
        $this->authUser = $parsed['user'] ?? '';
        $this->authPassword = $parsed['pass'] ?? '';
    }

    public function getDefaultUsername(): string
    {
        return $this->cyansUsername;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserState(string $username): array
    {
        return $this->request('GET', "/users/{$username}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOpenTopics(string $username): array
    {
        $state = $this->getUserState($username);
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
    public function getTopicDetails(string $topicId): array
    {
        return $this->request('GET', "/topics/{$topicId}");
    }

    /**
     * Search topics for the given user by matching subject/message text.
     *
     * The Cyans API has no search endpoint, so this fetches the user state
     * and filters topics client-side.
     *
     * @return list<array<string, mixed>>
     */
    public function searchTopics(string $username, string $query): array
    {
        $state = $this->getUserState($username);
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
    public function addPost(string $topicId, string $message, ?string $author = null): array
    {
        $author ??= $this->cyansUsername;

        return $this->request('POST', "/topics/{$topicId}/add-post", [
            'json' => [
                'author' => $author,
                'message' => $message,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $response = $this->httpClient->request($method, $this->baseUrl . $path, array_merge([
            'auth_basic' => [$this->authUser, $this->authPassword],
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
