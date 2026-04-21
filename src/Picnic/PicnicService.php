<?php

namespace App\Picnic;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PicnicService
{
    private const CLIENT_ID = 30100;
    private const CLIENT_VERSION = '1.15.77';
    private const USER_AGENT = 'okhttp/3.12.2';

    /** @var array<string, string> In-memory cache of auth tokens keyed by account key */
    private array $tokenCache = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PicnicConfigLoader $configLoader,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, country_code: string}>
     */
    public function listAccounts(): array
    {
        $out = [];

        foreach ($this->configLoader->getAccounts() as $account) {
            $out[] = [
                'key' => $account->key,
                'label' => $account->label,
                'country_code' => $account->countryCode,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function searchProducts(string $query, ?string $accountKey = null): array
    {
        $response = $this->request('GET', '/search?search_term=' . rawurlencode($query), accountKey: $accountKey);

        return [
            'query' => $query,
            'results' => $this->flattenSearchResults($response),
            'raw' => $response,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCart(?string $accountKey = null): array
    {
        return $this->request('GET', '/cart', accountKey: $accountKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function addToCart(string $productId, int $count = 1, ?string $accountKey = null): array
    {
        return $this->request('POST', '/cart/add_product', [
            'json' => [
                'product_id' => $productId,
                'count' => $count,
            ],
        ], $accountKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeFromCart(string $productId, int $count = 1, ?string $accountKey = null): array
    {
        return $this->request('POST', '/cart/remove_product', [
            'json' => [
                'product_id' => $productId,
                'count' => $count,
            ],
        ], $accountKey);
    }

    /**
     * @param list<string> $stateFilter Empty list = all; otherwise e.g. ["COMPLETED"] or ["CURRENT"]
     *
     * @return list<array<string, mixed>>
     */
    public function listDeliveries(array $stateFilter = [], ?string $accountKey = null): array
    {
        $response = $this->request('POST', '/deliveries', [
            'json' => array_values($stateFilter),
        ], $accountKey);

        if (isset($response[0]) || $response === []) {
            return $response;
        }

        return [$response];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDelivery(string $deliveryId, ?string $accountKey = null): array
    {
        return $this->request('GET', '/deliveries/' . rawurlencode($deliveryId), accountKey: $accountKey);
    }

    private function resolveAccount(?string $accountKey): PicnicAccountConfig
    {
        if ($accountKey !== null) {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No Picnic accounts configured');
        }

        return reset($accounts);
    }

    private function getAuthToken(PicnicAccountConfig $account, bool $forceRefresh = false): string
    {
        if (!$forceRefresh && isset($this->tokenCache[$account->key])) {
            return $this->tokenCache[$account->key];
        }

        $tokenFile = $this->configLoader->getTokenFilePath($account->username);

        if (!$forceRefresh && file_exists($tokenFile)) {
            $cached = trim((string) file_get_contents($tokenFile));
            if ($cached !== '') {
                $this->tokenCache[$account->key] = $cached;
                return $cached;
            }
        }

        $token = $this->login($account);
        file_put_contents($tokenFile, $token);
        chmod($tokenFile, 0600);
        $this->tokenCache[$account->key] = $token;

        return $token;
    }

    private function login(PicnicAccountConfig $account): string
    {
        if ($account->username === '' || $account->password === '') {
            throw new \RuntimeException(sprintf(
                'Picnic account "%s" is missing username or password',
                $account->key,
            ));
        }

        $response = $this->httpClient->request('POST', $this->baseUrl($account) . '/user/login', [
            'headers' => $this->defaultHeaders(),
            'json' => [
                'key' => $account->username,
                'secret' => md5($account->password),
                'client_id' => self::CLIENT_ID,
                'client_version' => self::CLIENT_VERSION,
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'Picnic login failed (HTTP %d): %s',
                $status,
                $response->getContent(false),
            ));
        }

        $headers = $response->getHeaders(false);
        $token = $headers['x-picnic-auth'][0] ?? null;

        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Picnic login succeeded but no x-picnic-auth header was returned');
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function request(string $method, string $path, array $options = [], ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);

        $attempt = 0;
        while (true) {
            $token = $this->getAuthToken($account, forceRefresh: $attempt > 0);

            $response = $this->httpClient->request($method, $this->baseUrl($account) . $path, array_merge([
                'headers' => array_merge($this->defaultHeaders(), [
                    'x-picnic-auth' => $token,
                ]),
            ], $options));

            $status = $response->getStatusCode();

            if ($status === 401 || $status === 403) {
                if ($attempt === 0) {
                    $attempt++;
                    continue;
                }
                throw new \RuntimeException(sprintf(
                    'Picnic API authentication failed (HTTP %d): %s',
                    $status,
                    $response->getContent(false),
                ));
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf(
                    'Picnic API error (HTTP %d %s %s): %s',
                    $status,
                    $method,
                    $path,
                    $response->getContent(false),
                ));
            }

            $data = $response->toArray(false);

            if (isset($data['error'])) {
                throw new \RuntimeException(sprintf(
                    'Picnic API returned error: %s',
                    $data['error']['message'] ?? json_encode($data['error']),
                ));
            }

            return $data;
        }
    }

    private function baseUrl(PicnicAccountConfig $account): string
    {
        return sprintf(
            'https://storefront-prod.%s.picnicinternational.com/api/%s',
            $account->countryCode,
            $account->apiVersion,
        );
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Flatten the nested search response into a flat list of products with a shared shape.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $response
     *
     * @return list<array<string, mixed>>
     */
    private function flattenSearchResults(array $response): array
    {
        $sections = isset($response[0]) ? $response : ($response['search_results'] ?? [$response]);
        $products = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $items = $section['items'] ?? $section['children'] ?? [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $product = $this->extractProduct($item);
                if ($product !== null) {
                    $products[] = $product;
                }
            }
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>|null
     */
    private function extractProduct(array $item): ?array
    {
        $type = $item['type'] ?? null;

        if ($type === 'CATEGORY' || $type === 'SECTION') {
            return null;
        }

        $id = $item['id'] ?? $item['product_id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        $price = $item['display_price'] ?? $item['price'] ?? null;

        return [
            'id' => (string) $id,
            'name' => $item['name'] ?? null,
            'unit_quantity' => $item['unit_quantity'] ?? null,
            'price_cents' => is_int($price) ? $price : null,
            'price' => is_int($price) ? number_format($price / 100, 2, '.', '') : null,
            'image_id' => $item['image_id'] ?? null,
            'max_count' => $item['max_count'] ?? null,
            'type' => $type,
        ];
    }
}
