<?php

namespace App\Integrations\Apify;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin client around the Apify REST API (v2).
 *
 * Apify has no official PHP SDK, so this wraps the documented REST endpoints
 * with Symfony's HttpClient (consistent with the other Prism integrations).
 * Authentication uses the profile's API token as a Bearer header, so the
 * token never ends up in the URL/query string.
 *
 * @see https://docs.apify.com/api/v2
 */
class ApifyService
{
    public function __construct(
        private readonly ApifyConfigLoader $configLoader,
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
     * Run an actor synchronously and return its default dataset items.
     *
     * Uses the `run-sync-get-dataset-items` endpoint, which starts the actor,
     * waits for it to finish (up to ~5 minutes) and returns the produced
     * dataset items as a JSON array. Ideal for short-running actors driven
     * from an MCP tool call.
     *
     * @param array<string, mixed>  $input   Actor input JSON (the actor's own input schema)
     * @param array<string, scalar> $options Run options: memory, timeout, maxItems, build, fields, ...
     *
     * @return list<array<string, mixed>> Dataset items
     */
    public function runActorSync(
        ?string $profileKey,
        string $actorId,
        array $input = [],
        array $options = [],
    ): array {
        $profile = $this->resolveProfile($profileKey);
        $this->assertConfigured($profile);

        $url = sprintf(
            '%s/acts/%s/run-sync-get-dataset-items',
            $profile->baseUrl,
            $this->normalizeActorId($actorId),
        );

        // Only forward a known/safe set of run options as query parameters.
        $query = [];
        foreach (['memory', 'timeout', 'maxItems', 'build', 'fields', 'omit', 'clean'] as $name) {
            if (isset($options[$name]) && $options[$name] !== '' && $options[$name] !== null) {
                $query[$name] = $options[$name];
            }
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $profile->apiToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'query' => $query,
            // An empty body still needs to be valid JSON for the actor input.
            'json' => (object) $input,
            // run-sync can hold the connection for up to ~5 minutes.
            'timeout' => 310,
        ]);

        $this->assertOk($response, sprintf('run actor "%s"', $actorId));

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            return [];
        }

        // run-sync-get-dataset-items returns a bare JSON array of items.
        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Fetch actor metadata, including (best-effort) its input schema from the
     * default build. Intended for development/discovery — e.g. an AI coding
     * agent inspecting an actor before wrapping it in a dedicated tool.
     *
     * @return array<string, mixed>
     */
    public function getActor(?string $profileKey, string $actorId): array
    {
        $profile = $this->resolveProfile($profileKey);
        $this->assertConfigured($profile);

        $normalized = $this->normalizeActorId($actorId);
        $actor = $this->request($profile, 'GET', '/acts/' . $normalized);
        $data = $actor['data'] ?? $actor;

        $summary = [
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? null,
            'username' => $data['username'] ?? null,
            'full_name' => isset($data['username'], $data['name'])
                ? $data['username'] . '/' . $data['name']
                : null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'is_public' => $data['isPublic'] ?? null,
            'default_run_options' => $data['defaultRunOptions'] ?? null,
            'stats' => $data['stats'] ?? null,
        ];

        $inputSchema = null;
        try {
            $build = $this->request($profile, 'GET', '/acts/' . $normalized . '/builds/default');
            $buildData = $build['data'] ?? $build;
            $rawSchema = $buildData['inputSchema'] ?? null;
            if (is_string($rawSchema) && $rawSchema !== '') {
                $decoded = json_decode($rawSchema, true);
                $inputSchema = is_array($decoded) ? $decoded : $rawSchema;
            } elseif (is_array($rawSchema)) {
                $inputSchema = $rawSchema;
            }
        } catch (\Throwable) {
            // Input schema is best-effort; not all actors/builds expose it.
        }

        return [
            'actor' => $summary,
            'input_schema' => $inputSchema,
        ];
    }

    private function resolveProfile(?string $profileKey): ApifyProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No Apify profiles configured for this server');
        }

        return reset($profiles);
    }

    private function assertConfigured(ApifyProfileConfig $profile): void
    {
        if ($profile->baseUrl === '' || $profile->apiToken === '') {
            throw new \RuntimeException(sprintf(
                'Apify profile "%s" is missing base_url or api_token',
                $profile->key,
            ));
        }
    }

    /**
     * Normalize an actor identifier for use in a URL path. Accepts the
     * "username/actorName" form (which Apify expects as "username~actorName")
     * as well as the already-tilded form or a raw actor ID.
     */
    private function normalizeActorId(string $actorId): string
    {
        $actorId = trim($actorId);
        if ($actorId === '') {
            throw new \InvalidArgumentException('Actor id must not be empty');
        }

        return rawurlencode(str_replace('/', '~', $actorId));
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    private function request(ApifyProfileConfig $profile, string $method, string $path, array $query = []): array
    {
        $response = $this->httpClient->request($method, $profile->baseUrl . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $profile->apiToken,
                'Accept' => 'application/json',
            ],
            'query' => array_filter($query, static fn ($v) => $v !== null && $v !== ''),
            'timeout' => 30,
        ]);

        $this->assertOk($response, sprintf('%s %s', $method, $path));

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : ['value' => $data];
    }

    private function assertOk(ResponseInterface $response, string $action): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Apify API error while trying to %s (HTTP %d): %s',
                $action,
                $statusCode,
                $response->getContent(false),
            ));
        }
    }
}
