<?php

namespace App\Integrations\Canva;

use App\Config\ServerContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CanvaService
{
    public const AUTHORIZE_URL = 'https://www.canva.com/api/oauth/authorize';
    public const TOKEN_URL = 'https://api.canva.com/rest/v1/oauth/token';

    public function __construct(
        private readonly CanvaConfigLoader $configLoader,
        private readonly CanvaTokenStore $tokenStore,
        private readonly ServerContext $serverContext,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, connected: bool, has_credentials: bool, scopes: list<string>}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'connected' => $profile->isConnected(),
                'has_credentials' => $profile->hasCredentials(),
                'scopes' => $profile->scopes,
            ];
        }

        return $profiles;
    }

    // ──────────────────────────────────────────────────────────────────
    // OAuth (Authorization Code flow with PKCE)
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $scopes
     */
    public function buildAuthorizationUrl(
        string $profileKey,
        string $redirectUri,
        string $codeChallenge,
        string $state,
        array $scopes,
    ): string {
        $profile = $this->configLoader->getProfile($profileKey);

        if (!$profile->hasCredentials()) {
            throw new \RuntimeException(sprintf(
                'Canva profile "%s" is missing client_id / client_secret in prism.config.yaml.',
                $profileKey,
            ));
        }

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $profile->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes !== [] ? $scopes : $profile->scopes),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZE_URL . '?' . $params;
    }

    /**
     * Exchange an authorization code for tokens and persist them to the config file.
     *
     * @return array{scope: string, token_expires_at: int}
     */
    public function exchangeAuthorizationCode(
        string $profileKey,
        string $code,
        string $codeVerifier,
        string $redirectUri,
    ): array {
        $profile = $this->configLoader->getProfile($profileKey);

        $data = $this->tokenRequest($profile, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ]);

        $updated = $this->storeTokenResponse($profile, $data);

        return [
            'scope' => (string) ($data['scope'] ?? ''),
            'token_expires_at' => $updated->tokenExpiresAt,
        ];
    }

    public function disconnect(string $profileKey): void
    {
        // Ensure the profile exists before clearing.
        $this->configLoader->getProfile($profileKey);
        $this->tokenStore->clearTokens($this->serverContext->getServerName(), $profileKey);
    }

    // ──────────────────────────────────────────────────────────────────
    // Designs API
    // ──────────────────────────────────────────────────────────────────

    /**
     * List the user's designs (and designs shared with the user).
     *
     * @return array<string, mixed>
     */
    public function listDesigns(
        ?string $profileKey = null,
        ?string $query = null,
        ?string $continuation = null,
        ?string $ownership = null,
        ?string $sortBy = null,
        ?int $limit = null,
    ): array {
        $profile = $this->resolveProfile($profileKey);

        return $this->apiGet($profile, '/v1/designs', [
            'query' => $query,
            'continuation' => $continuation,
            'ownership' => $ownership,
            'sort_by' => $sortBy,
            'limit' => $limit,
        ]);
    }

    /**
     * Get the metadata for a single design.
     *
     * @return array<string, mixed>
     */
    public function getDesign(?string $profileKey, string $designId): array
    {
        $profile = $this->resolveProfile($profileKey);

        return $this->apiGet($profile, '/v1/designs/' . rawurlencode($designId));
    }

    /**
     * List the pages (with thumbnails) of a design.
     *
     * @return array<string, mixed>
     */
    public function getDesignPages(
        ?string $profileKey,
        string $designId,
        ?int $offset = null,
        ?int $limit = null,
    ): array {
        $profile = $this->resolveProfile($profileKey);

        return $this->apiGet($profile, '/v1/designs/' . rawurlencode($designId) . '/pages', [
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────

    private function resolveProfile(?string $profileKey): CanvaProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if ($profiles === []) {
            throw new \RuntimeException('No Canva profiles configured for this server.');
        }

        return reset($profiles);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    private function apiGet(CanvaProfileConfig $profile, string $path, array $query = []): array
    {
        $token = $this->validAccessToken($profile);

        $response = $this->httpClient->request('GET', $profile->apiBaseUrl . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'query' => array_filter($query, static fn ($v) => $v !== null && $v !== ''),
            'timeout' => 30,
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException(sprintf(
                'Canva API error (HTTP %d): %s',
                $status,
                $response->getContent(false),
            ));
        }

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : ['value' => $data];
    }

    private function validAccessToken(CanvaProfileConfig $profile): string
    {
        if ($profile->isAccessTokenValid()) {
            return $profile->accessToken;
        }

        if ($profile->refreshToken === '') {
            throw new \RuntimeException(sprintf(
                'Canva profile "%s" is not connected. Open the Canva page in the Prism admin for this server and click "Connect".',
                $profile->key,
            ));
        }

        return $this->refreshAccessToken($profile)->accessToken;
    }

    private function refreshAccessToken(CanvaProfileConfig $profile): CanvaProfileConfig
    {
        $data = $this->tokenRequest($profile, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $profile->refreshToken,
        ]);

        return $this->storeTokenResponse($profile, $data);
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(CanvaProfileConfig $profile, array $body): array
    {
        if (!$profile->hasCredentials()) {
            throw new \RuntimeException(sprintf(
                'Canva profile "%s" is missing client_id / client_secret in prism.config.yaml.',
                $profile->key,
            ));
        }

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($profile->clientId . ':' . $profile->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
            'timeout' => 30,
        ]);

        $content = $response->getContent(false);
        $data = $content !== '' ? json_decode($content, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $message = (string) ($data['error_description'] ?? $data['error'] ?? $data['message'] ?? $content);

            throw new \RuntimeException(sprintf('Canva token request failed (HTTP %d): %s', $status, $message));
        }

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Canva token response did not contain an access_token.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeTokenResponse(CanvaProfileConfig $profile, array $data): CanvaProfileConfig
    {
        $accessToken = (string) $data['access_token'];
        $refreshToken = (string) ($data['refresh_token'] ?? $profile->refreshToken);
        $expiresIn = (int) ($data['expires_in'] ?? 0);
        $expiresAt = $expiresIn > 0 ? time() + $expiresIn : 0;

        $this->tokenStore->persistTokens(
            $this->serverContext->getServerName(),
            $profile->key,
            $accessToken,
            $refreshToken,
            $expiresAt,
        );

        return $profile->withTokens($accessToken, $refreshToken, $expiresAt);
    }
}
