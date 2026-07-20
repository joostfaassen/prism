<?php

namespace App\Integrations\Picnic;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class PicnicService
{
    private const CLIENT_ID = 30100;
    private const AGENT = '30100;1.236.1-15553;';
    private const DEVICE_ID = '3C417201548B2E3B';
    private const USER_AGENT = 'okhttp/4.9.0';

    /** @var array<string, string> */
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
     * @return array{query: string, count: int, products: list<array<string, mixed>>}
     */
    public function searchProducts(string $query, ?string $accountKey = null, int $limit = 20): array
    {
        $account = $this->resolveAccount($accountKey);
        $limit = max(1, min(50, $limit));
        $products = [];

        try {
            $page = $this->request(
                'GET',
                '/pages/search-page-results?search_term=' . rawurlencode($query),
                ['picnic_headers' => true],
                $account->key,
            );
            foreach (PicnicFusion::collectKeyed($page, 'sellingUnit') as $unit) {
                $normalized = $this->normalizeSellingUnit($unit, $account);
                if ($normalized !== null) {
                    $products[] = $normalized;
                }
            }
        } catch (\Throwable) {
            $response = $this->request(
                'GET',
                '/search?search_term=' . rawurlencode($query),
                accountKey: $account->key,
            );
            $products = $this->flattenLegacySearchResults($response, $account);
        }

        $products = $this->dedupeById($products);
        if (count($products) > $limit) {
            $products = array_slice($products, 0, $limit);
        }

        return [
            'query' => $query,
            'count' => count($products),
            'products' => $products,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProduct(string $productId, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $productId = $this->normalizeProductId($productId);

        $page = $this->request(
            'GET',
            '/pages/product-details-page-root?id=' . rawurlencode($productId)
                . '&show_category_action=true&show_remove_from_purchases_page_action=true',
            ['picnic_headers' => true],
            $account->key,
        );

        $units = PicnicFusion::collectKeyed($page, 'sellingUnit');
        $main = null;
        foreach ($units as $unit) {
            if (($unit['id'] ?? null) === $productId) {
                $main = $unit;
                break;
            }
        }
        $main ??= $units[0] ?? null;
        $light = $main !== null ? $this->normalizeSellingUnit($main, $account) : [
            'id' => $productId,
            'name' => null,
            'unit_quantity' => null,
            'price_cents' => null,
            'price_eur' => null,
            'currency' => 'EUR',
            'image_id' => null,
            'image_url' => null,
            'max_count' => null,
        ];

        $imageIds = [];
        PicnicFusion::walk($page, static function (array $n) use (&$imageIds): void {
            if (($n['type'] ?? null) === 'IMAGE' && is_array($n['source'] ?? null) && is_string($n['source']['id'] ?? null)) {
                $imageIds[$n['source']['id']] = $n['source']['id'];
            }
            if (is_string($n['image_id'] ?? null)) {
                $imageIds[$n['image_id']] = $n['image_id'];
            }
        });
        if (is_string($light['image_id'] ?? null)) {
            $imageIds[$light['image_id']] = $light['image_id'];
        }

        $imageUrls = [];
        foreach (array_values($imageIds) as $imageId) {
            $imageUrls[] = PicnicImage::url($account->countryCode, $imageId, 'medium');
        }

        $markdown = PicnicFusion::collectMarkdownLines($page, [
            'analytics', 'tracking_attributes', 'loadingConfig', 'errorConfig',
        ]);

        return array_merge($light ?? [], [
            'id' => $productId,
            'brand' => $main['brand'] ?? null,
            'description' => $markdown[0] ?? null,
            'highlights' => array_slice($markdown, 1, 8),
            'allergens' => [],
            'promotion' => isset($main['promotion_id']) ? [
                'id' => $main['promotion_id'],
                'label' => $main['promotion_label'] ?? null,
            ] : null,
            'image_ids' => array_values($imageIds),
            'image_urls' => $imageUrls,
            'parse_warning' => $main === null
                ? 'Product page returned no sellingUnit; fields may be incomplete.'
                : null,
        ]);
    }

    /**
     * @return array{image_id: string, size: string, url: string}
     */
    public function getImageUrl(string $imageId, string $size = 'medium', ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $imageId = trim($imageId);
        if ($imageId === '') {
            throw new \InvalidArgumentException('image_id is required');
        }

        return [
            'image_id' => $imageId,
            'size' => in_array($size, PicnicImage::SIZES, true) ? $size : 'medium',
            'url' => PicnicImage::url($account->countryCode, $imageId, $size),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCart(?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $raw = $this->request('GET', '/cart', ['picnic_headers' => true], $account->key);

        return $this->normalizeCart($raw, $account);
    }

    /**
     * @return array<string, mixed>
     */
    public function addToCart(string $productId, int $count = 1, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $raw = $this->request('POST', '/cart/add_product', [
            'picnic_headers' => true,
            'json' => [
                'product_id' => $this->normalizeProductId($productId),
                'count' => max(1, $count),
            ],
        ], $account->key);

        return $this->normalizeCart($raw, $account);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeFromCart(string $productId, int $count = 1, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $raw = $this->request('POST', '/cart/remove_product', [
            'picnic_headers' => true,
            'json' => [
                'product_id' => $this->normalizeProductId($productId),
                'count' => max(1, $count),
            ],
        ], $account->key);

        return $this->normalizeCart($raw, $account);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearCart(?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $raw = $this->request('POST', '/cart/clear', ['picnic_headers' => true], $account->key);

        return $this->normalizeCart($raw, $account);
    }

    /**
     * @return array{ok: true, channel: string}
     */
    public function generate2faCode(string $channel = 'SMS', ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $this->ensureAuthTokenAllowing2fa($account);
        $this->request('POST', '/user/2fa/generate', [
            'picnic_headers' => true,
            'json' => ['channel' => $channel],
        ], $account->key);

        return ['ok' => true, 'channel' => $channel];
    }

    /**
     * @return array{ok: true}
     */
    public function verify2faCode(string $code, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $this->ensureAuthTokenAllowing2fa($account);
        $token = $this->getAuthToken($account);

        $response = $this->httpClient->request('POST', $this->baseUrl($account) . '/user/2fa/verify', [
            'headers' => array_merge($this->defaultHeaders($account), $this->picnicHeaders(), [
                'x-picnic-auth' => $token,
            ]),
            'json' => ['otp' => $code],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'Picnic 2FA verification failed (HTTP %d): %s',
                $status,
                $response->getContent(false),
            ));
        }

        $headers = $response->getHeaders(false);
        $newToken = $headers['x-picnic-auth'][0] ?? null;
        if (!is_string($newToken) || $newToken === '') {
            throw new \RuntimeException('Picnic 2FA verification succeeded but no x-picnic-auth header was returned');
        }

        $this->persistToken($account, $newToken);

        return ['ok' => true];
    }

    /**
     * @return array{count: int, recipes: list<array<string, mixed>>}
     */
    public function browseRecipes(?string $accountKey = null, ?string $segment = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $page = $this->request(
            'GET',
            '/pages/cookbook-page-content',
            ['picnic_headers' => true],
            $account->key,
        );

        $recipes = PicnicRecipeParser::parseRecipeList(
            $page,
            PicnicImage::staticBaseUrl($account->countryCode),
        );

        if ($segment !== null && $segment !== '') {
            $segmentUpper = strtoupper($segment);
            $recipes = array_values(array_filter(
                $recipes,
                static function (array $recipe) use ($segmentUpper): bool {
                    foreach ($recipe['segments'] as $seg) {
                        if (strtoupper((string) $seg) === $segmentUpper || str_contains(strtoupper((string) $seg), $segmentUpper)) {
                            return true;
                        }
                    }

                    return false;
                },
            ));
        }

        return [
            'count' => count($recipes),
            'recipes' => $recipes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecipe(string $recipeIdOrUrl, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $recipeId = PicnicRecipeParser::resolveRecipeId($recipeIdOrUrl);

        $page = $this->request(
            'GET',
            '/pages/selling-group-details-page?selling_group_id=' . rawurlencode($recipeId),
            ['picnic_headers' => true],
            $account->key,
        );

        return PicnicRecipeParser::parseRecipeDetails(
            $page,
            $recipeId,
            PicnicImage::staticBaseUrl($account->countryCode),
            $account->countryCode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function addRecipeToCart(string $recipeIdOrUrl, ?int $portions = null, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $recipeId = PicnicRecipeParser::resolveRecipeId($recipeIdOrUrl);

        $payload = ['selling_group_id' => $recipeId];
        if ($portions !== null) {
            $payload['portions'] = $portions;
        }

        $this->request('POST', '/pages/task/assign-selling-group-to-basket', [
            'picnic_headers' => true,
            'json' => ['payload' => $payload],
        ], $account->key);

        return $this->getCart($account->key);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeRecipeFromCart(string $recipeIdOrUrl, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $recipeId = PicnicRecipeParser::resolveRecipeId($recipeIdOrUrl);

        $this->request('POST', '/pages/task/remove-selling-group-from-basket', [
            'picnic_headers' => true,
            'json' => [
                'payload' => [
                    'selling_group_id' => $recipeId,
                ],
            ],
        ], $account->key);

        return $this->getCart($account->key);
    }

    /**
     * @return array{ok: true, recipe_id: string, saved: bool}
     */
    public function saveRecipe(string $recipeIdOrUrl, bool $saved = true, ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $recipeId = PicnicRecipeParser::resolveRecipeId($recipeIdOrUrl);

        $this->request('POST', '/pages/task/recipe-saving', [
            'picnic_headers' => true,
            'json' => [
                'payload' => [
                    'recipe_id' => $recipeId,
                    'saved_at' => $saved ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z') : null,
                ],
            ],
        ], $account->key);

        return ['ok' => true, 'recipe_id' => $recipeId, 'saved' => $saved];
    }

    /**
     * @param list<string> $stateFilter
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
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if ($accounts === []) {
            throw new \RuntimeException('No Picnic accounts configured');
        }

        return reset($accounts);
    }

    /**
     * Ensure a session token exists even when login reports that 2FA is still required.
     */
    private function ensureAuthTokenAllowing2fa(PicnicAccountConfig $account): void
    {
        try {
            $this->getAuthToken($account);
        } catch (PicnicTwoFactorRequiredException) {
            // Token was persisted during login; 2FA endpoints can use it.
        }
    }

    private function getAuthToken(PicnicAccountConfig $account, bool $forceRefresh = false): string
    {
        if (!$forceRefresh && isset($this->tokenCache[$account->key])) {
            return $this->tokenCache[$account->key];
        }

        if (!$forceRefresh && $account->authKey !== null) {
            $this->tokenCache[$account->key] = $account->authKey;

            return $account->authKey;
        }

        $tokenFile = $this->configLoader->getTokenFilePath($account->username);
        if (!$forceRefresh && file_exists($tokenFile)) {
            $cached = trim((string) file_get_contents($tokenFile));
            if ($cached !== '') {
                $this->tokenCache[$account->key] = $cached;

                return $cached;
            }
        }

        return $this->login($account);
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
            'headers' => $this->defaultHeaders($account),
            'json' => [
                'key' => $account->username,
                'secret' => md5($account->password),
                'client_id' => self::CLIENT_ID,
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

        $this->persistToken($account, $token);

        $body = $this->decodeJsonBody($response);
        if (($body['second_factor_authentication_required'] ?? false) === true) {
            throw new PicnicTwoFactorRequiredException($account->key);
        }

        return $token;
    }

    private function persistToken(PicnicAccountConfig $account, string $token): void
    {
        $tokenFile = $this->configLoader->getTokenFilePath($account->username);
        file_put_contents($tokenFile, $token);
        chmod($tokenFile, 0600);
        $this->tokenCache[$account->key] = $token;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|list<mixed>
     */
    private function request(string $method, string $path, array $options = [], ?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);
        $picnicHeaders = (bool) ($options['picnic_headers'] ?? false);
        unset($options['picnic_headers']);

        $attempt = 0;
        while (true) {
            $token = $this->getAuthToken($account, forceRefresh: $attempt > 0);

            $headers = array_merge($this->defaultHeaders($account), [
                'x-picnic-auth' => $token,
            ]);
            if ($picnicHeaders) {
                $headers = array_merge($headers, $this->picnicHeaders());
            }

            $response = $this->httpClient->request(
                $method,
                $this->baseUrl($account) . $path,
                array_merge(['headers' => $headers], $options),
            );

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

            if ($status === 204) {
                return [];
            }

            $content = $response->getContent(false);
            if ($content === '' || $content === 'null') {
                return [];
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return [];
            }

            if (isset($data['error'])) {
                throw new \RuntimeException(sprintf(
                    'Picnic API returned error: %s',
                    is_array($data['error'])
                        ? ($data['error']['message'] ?? json_encode($data['error']))
                        : (string) $data['error'],
                ));
            }

            return $data;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(ResponseInterface $response): array
    {
        $content = $response->getContent(false);
        if ($content === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
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
    private function defaultHeaders(PicnicAccountConfig $account): array
    {
        $lang = match ($account->countryCode) {
            'de' => 'de',
            'fr' => 'fr',
            default => 'nl',
        };

        return [
            'User-Agent' => self::USER_AGENT,
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
            'Accept-Language' => $lang,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function picnicHeaders(): array
    {
        return [
            'x-picnic-agent' => self::AGENT,
            'x-picnic-did' => self::DEVICE_ID,
        ];
    }

    private function normalizeProductId(string $productId): string
    {
        $productId = trim($productId);
        if ($productId === '') {
            throw new \InvalidArgumentException('product_id is required');
        }
        if (!str_starts_with($productId, 's') && ctype_digit($productId)) {
            return 's' . $productId;
        }

        return $productId;
    }

    /**
     * @param array<string, mixed> $unit
     *
     * @return array<string, mixed>|null
     */
    private function normalizeSellingUnit(array $unit, PicnicAccountConfig $account): ?array
    {
        $id = $unit['id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        $price = $unit['display_price'] ?? $unit['price'] ?? null;
        $priceCents = is_int($price) ? $price : (is_numeric($price) ? (int) $price : null);
        $imageId = is_string($unit['image_id'] ?? null) ? $unit['image_id'] : null;

        return [
            'id' => (string) $id,
            'name' => $unit['name'] ?? null,
            'unit_quantity' => $unit['unit_quantity'] ?? null,
            'price_cents' => $priceCents,
            'price_eur' => $priceCents !== null ? round($priceCents / 100, 2) : null,
            'currency' => 'EUR',
            'image_id' => $imageId,
            'image_url' => $imageId !== null
                ? PicnicImage::url($account->countryCode, $imageId, 'medium')
                : null,
            'max_count' => $unit['max_count'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|list<mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function flattenLegacySearchResults(array $response, PicnicAccountConfig $account): array
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
                $type = $item['type'] ?? null;
                if ($type === 'CATEGORY' || $type === 'SECTION') {
                    continue;
                }
                $normalized = $this->normalizeSellingUnit($item, $account);
                if ($normalized !== null) {
                    $products[] = $normalized;
                }
            }
        }

        return $products;
    }

    /**
     * @param list<array<string, mixed>> $products
     *
     * @return list<array<string, mixed>>
     */
    private function dedupeById(array $products): array
    {
        $seen = [];
        $out = [];
        foreach ($products as $product) {
            $id = $product['id'] ?? null;
            if (!is_string($id) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $product;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|list<mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeCart(array $raw, PicnicAccountConfig $account): array
    {
        $items = [];
        PicnicFusion::walk($raw, function (array $n) use (&$items, $account): void {
            $id = $n['id'] ?? null;
            $name = $n['name'] ?? null;
            if (!is_string($id) || !is_string($name)) {
                return;
            }
            if (!(str_starts_with($id, 's') || preg_match('/^\d+$/', $id) === 1)) {
                return;
            }

            $quantity = null;
            if (is_int($n['count'] ?? null)) {
                $quantity = $n['count'];
            } elseif (is_array($n['decorators'] ?? null)) {
                foreach ($n['decorators'] as $decorator) {
                    if (is_array($decorator) && ($decorator['type'] ?? null) === 'QUANTITY' && is_int($decorator['quantity'] ?? null)) {
                        $quantity = $decorator['quantity'];
                        break;
                    }
                }
            }

            $price = $n['display_price'] ?? $n['price'] ?? null;
            $priceCents = is_int($price) ? $price : null;
            $imageId = is_string($n['image_id'] ?? null) ? $n['image_id'] : null;

            $items[$id] = [
                'id' => str_starts_with($id, 's') ? $id : 's' . $id,
                'name' => $name,
                'quantity' => $quantity,
                'unit_quantity' => $n['unit_quantity'] ?? null,
                'price_cents' => $priceCents,
                'price_eur' => $priceCents !== null ? round($priceCents / 100, 2) : null,
                'image_id' => $imageId,
                'image_url' => $imageId !== null
                    ? PicnicImage::url($account->countryCode, $imageId, 'medium')
                    : null,
            ];
        });

        $itemList = array_values($items);
        $totalCents = null;
        foreach (['total_price', 'display_price', 'price'] as $key) {
            if (is_int($raw[$key] ?? null)) {
                $totalCents = $raw[$key];
                break;
            }
        }

        if ($totalCents === null) {
            $sum = 0;
            $any = false;
            foreach ($itemList as $item) {
                if (is_int($item['price_cents']) && is_int($item['quantity'])) {
                    $sum += $item['price_cents'] * $item['quantity'];
                    $any = true;
                } elseif (is_int($item['price_cents'])) {
                    $sum += $item['price_cents'];
                    $any = true;
                }
            }
            $totalCents = $any ? $sum : null;
        }

        return [
            'item_count' => count($itemList),
            'items' => $itemList,
            'total_price_cents' => $totalCents,
            'total_price_eur' => $totalCents !== null ? round($totalCents / 100, 2) : null,
            'currency' => 'EUR',
            'note' => 'Picnic cart is the shopping list. Images are HTTPS URLs.',
        ];
    }
}
