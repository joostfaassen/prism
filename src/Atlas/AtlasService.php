<?php

namespace App\Atlas;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class AtlasService
{
    public function __construct(
        private readonly AtlasConfigLoader $configLoader,
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
     * @return array<string, mixed>
     */
    public function discovery(string $atlas): array
    {
        return $this->requestJson($atlas, 'GET', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function list(string $atlas, string $path = ''): array
    {
        return $this->requestJson($atlas, 'GET', $this->endpointWithPath('list', $path));
    }

    /**
     * @return array<string, mixed>
     */
    public function tree(string $atlas, string $path = '', int $depth = 3): array
    {
        return $this->requestJson($atlas, 'GET', $this->endpointWithPath('tree', $path), [
            'depth' => max(1, min($depth, 12)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function content(string $atlas, string $path): array
    {
        return $this->requestJson($atlas, 'GET', 'content/' . $this->encodePath($path));
    }

    /**
     * @return array{path: string, content_type: string|null, bytes: int, truncated: bool, base64: string}
     */
    public function raw(string $atlas, string $path, int $maxBytes = 1048576): array
    {
        $response = $this->request($atlas, 'GET', 'raw/' . $this->encodePath($path), accept: '*/*');
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Atlas API error (HTTP %d): %s',
                $statusCode,
                $content,
            ));
        }

        $maxBytes = max(1, $maxBytes);
        $bytes = strlen($content);
        $truncated = $bytes > $maxBytes;
        if ($truncated) {
            $content = substr($content, 0, $maxBytes);
        }

        $headers = $response->getHeaders(false);

        return [
            'path' => $path,
            'content_type' => $headers['content-type'][0] ?? null,
            'bytes' => $bytes,
            'truncated' => $truncated,
            'base64' => base64_encode($content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $atlas, string $query): array
    {
        return $this->requestJson($atlas, 'GET', 'search', ['q' => $query]);
    }

    /**
     * @return array<string, mixed>
     */
    public function grep(
        string $atlas,
        string $query,
        ?string $path = null,
        bool $regex = false,
        bool $ignoreCase = false,
        ?string $glob = null,
        int $max = 200,
    ): array {
        $params = [
            'q' => $query,
            'regex' => $regex ? 1 : 0,
            'ignore_case' => $ignoreCase ? 1 : 0,
            'max' => max(1, min($max, 1000)),
        ];

        if ($path !== null && $path !== '') {
            $params['path'] = $path;
        }
        if ($glob !== null && $glob !== '') {
            $params['glob'] = $glob;
        }

        return $this->requestJson($atlas, 'GET', 'grep', $params);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $atlas, string $method, string $endpoint, array $query = []): array
    {
        $response = $this->request($atlas, $method, $endpoint, $query);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Atlas API error (HTTP %d): %s',
                $statusCode,
                $content,
            ));
        }

        if ($content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(
        string $atlas,
        string $method,
        string $endpoint,
        array $query = [],
        string $accept = 'application/json',
    ): ResponseInterface
    {
        $account = $this->configLoader->getAccount($atlas);

        if ($account->baseUrl === '' || $account->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'Atlas account "%s" is missing base_url or api_key',
                $atlas,
            ));
        }

        $url = $account->baseUrl . '/api';
        if ($endpoint !== '') {
            $url .= '/' . ltrim($endpoint, '/');
        }

        $options = [
            'auth_basic' => ['ATLAS', $account->apiKey],
            'headers' => [
                'Accept' => $accept,
            ],
            'timeout' => 30,
        ];

        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->httpClient->request($method, $url, $options);
    }

    private function encodePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function endpointWithPath(string $endpoint, string $path): string
    {
        $path = $this->encodePath($path);

        if ($path === '') {
            return $endpoint;
        }

        return $endpoint . '/' . $path;
    }
}
