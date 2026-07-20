<?php

namespace App\Integrations\Ga4;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the Google Analytics 4 Data API (v1beta).
 *
 * Authentication uses a Google service account: we build a short-lived RS256
 * JWT signed with the profile's private key, exchange it for an OAuth2 access
 * token, and send that as a Bearer token on every Data API request. Tokens are
 * cached in-memory for the lifetime of the request (same approach as
 * TransipService).
 *
 * @see https://developers.google.com/analytics/devguides/reporting/data/v1
 */
class Ga4Service
{
    private const DATA_API_BASE = 'https://analyticsdata.googleapis.com/v1beta';
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    /** @var array<string, string> cached access tokens keyed by profile key */
    private array $tokenCache = [];

    public function __construct(
        private readonly Ga4ConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, default_property_id: string|null}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'default_property_id' => $profile->defaultPropertyId,
            ];
        }

        return $profiles;
    }

    /**
     * Metadata (available dimensions and metrics) for a property. Useful for
     * discovering valid field names before building a runReport query.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(?string $profileKey, ?string $propertyId): array
    {
        $property = $this->resolvePropertyId($profileKey, $propertyId);
        $token = $this->getToken($this->resolveProfile($profileKey));

        return $this->request(
            'GET',
            sprintf('/properties/%s/metadata', rawurlencode($property)),
            $token,
        );
    }

    /**
     * Run an arbitrary GA4 runReport query.
     *
     * @param list<string>                     $dimensions
     * @param list<string>                     $metrics
     * @param list<array{startDate: string, endDate: string}> $dateRanges
     * @param array<string, mixed>|null        $dimensionFilter GA4 FilterExpression
     * @param array<string, mixed>|null        $metricFilter    GA4 FilterExpression
     * @param list<array<string, mixed>>|null  $orderBys        GA4 OrderBy list
     *
     * @return array<string, mixed>
     */
    public function runReport(
        ?string $profileKey,
        ?string $propertyId,
        array $dimensions,
        array $metrics,
        array $dateRanges,
        ?array $dimensionFilter = null,
        ?array $metricFilter = null,
        ?array $orderBys = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $property = $this->resolvePropertyId($profileKey, $propertyId);
        $token = $this->getToken($this->resolveProfile($profileKey));

        $body = [
            'dimensions' => array_map(static fn(string $name): array => ['name' => $name], $dimensions),
            'metrics' => array_map(static fn(string $name): array => ['name' => $name], $metrics),
            'dateRanges' => $dateRanges,
        ];

        if ($dimensionFilter !== null) {
            $body['dimensionFilter'] = $dimensionFilter;
        }
        if ($metricFilter !== null) {
            $body['metricFilter'] = $metricFilter;
        }
        if ($orderBys !== null) {
            $body['orderBys'] = $orderBys;
        }
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        if ($offset !== null) {
            $body['offset'] = $offset;
        }

        return $this->request(
            'POST',
            sprintf('/properties/%s:runReport', rawurlencode($property)),
            $token,
            $body,
        );
    }

    private function resolvePropertyId(?string $profileKey, ?string $propertyId): string
    {
        if ($propertyId !== null && trim($propertyId) !== '') {
            return $this->normalizePropertyId($propertyId);
        }

        $default = $this->resolveProfile($profileKey)->defaultPropertyId;
        if ($default !== null && trim($default) !== '') {
            return $this->normalizePropertyId($default);
        }

        throw new \InvalidArgumentException(
            'No property_id provided and no property_id configured for this GA4 profile.',
        );
    }

    /**
     * The Data API addresses properties by their numeric id. Accept both the
     * bare id ("123456789") and the "properties/123456789" form.
     */
    private function normalizePropertyId(string $propertyId): string
    {
        $propertyId = trim($propertyId);
        if (str_starts_with($propertyId, 'properties/')) {
            $propertyId = substr($propertyId, strlen('properties/'));
        }

        return $propertyId;
    }

    private function resolveProfile(?string $profileKey): Ga4ProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No GA4 profiles configured for this server');
        }

        return reset($profiles);
    }

    private function getToken(Ga4ProfileConfig $profile): string
    {
        if (isset($this->tokenCache[$profile->key])) {
            return $this->tokenCache[$profile->key];
        }

        if (trim($profile->clientEmail) === '' || trim($profile->privateKey) === '') {
            throw new \RuntimeException(sprintf(
                'GA4 profile "%s" is missing service-account credentials (client_email / private_key or credentials_file)',
                $profile->key,
            ));
        }

        $privateKey = openssl_pkey_get_private($profile->privateKey);
        if ($privateKey === false) {
            throw new \RuntimeException(sprintf(
                'GA4 profile "%s" has an invalid service-account private key (%s)',
                $profile->key,
                openssl_error_string() ?: 'unknown error',
            ));
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $profile->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $profile->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $signingInput = $this->base64UrlEncode($this->jsonEncode($header))
            . '.' . $this->base64UrlEncode($this->jsonEncode($claims));

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException(sprintf(
                'Failed to sign GA4 JWT assertion: %s',
                openssl_error_string() ?: 'unknown error',
            ));
        }

        $assertion = $signingInput . '.' . $this->base64UrlEncode($signature);

        $response = $this->httpClient->request('POST', $profile->tokenUri, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
            'timeout' => 30,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException(sprintf(
                'GA4 token request failed (HTTP %d): %s',
                $status,
                $response->getContent(false),
            ));
        }

        $data = $response->toArray(false);
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('GA4 token request did not return an access_token');
        }

        return $this->tokenCache[$profile->key] = $token;
    }

    /**
     * @param array<string, mixed> $body optional JSON request body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, string $token, array $body = []): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($method !== 'GET') {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, self::DATA_API_BASE . $path, $options);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $error = $response->toArray(false)['error']['message'] ?? $response->getContent(false);
            throw new \RuntimeException(sprintf('GA4 Data API error (HTTP %d): %s', $status, $error));
        }

        $content = $response->getContent(false);
        if (trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
