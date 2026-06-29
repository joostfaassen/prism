<?php

namespace App\Canva;

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
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'connected' => $account->isConnected(),
                'has_credentials' => $account->hasCredentials(),
                'scopes' => $account->scopes,
            ];
        }

        return $accounts;
    }

    // ──────────────────────────────────────────────────────────────────
    // OAuth (Authorization Code flow with PKCE)
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $scopes
     */
    public function buildAuthorizationUrl(
        string $accountKey,
        string $redirectUri,
        string $codeChallenge,
        string $state,
        array $scopes,
    ): string {
        $account = $this->configLoader->getAccount($accountKey);

        if (!$account->hasCredentials()) {
            throw new \RuntimeException(sprintf(
                'Canva account "%s" is missing client_id / client_secret in prism.config.yaml.',
                $accountKey,
            ));
        }

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $account->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes !== [] ? $scopes : $account->scopes),
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
        string $accountKey,
        string $code,
        string $codeVerifier,
        string $redirectUri,
    ): array {
        $account = $this->configLoader->getAccount($accountKey);

        $data = $this->tokenRequest($account, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
        ]);

        $updated = $this->storeTokenResponse($account, $data);

        return [
            'scope' => (string) ($data['scope'] ?? ''),
            'token_expires_at' => $updated->tokenExpiresAt,
        ];
    }

    public function disconnect(string $accountKey): void
    {
        // Ensure the account exists before clearing.
        $this->configLoader->getAccount($accountKey);
        $this->tokenStore->clearTokens($this->serverContext->getServerName(), $accountKey);
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
        ?string $accountKey = null,
        ?string $query = null,
        ?string $continuation = null,
        ?string $ownership = null,
        ?string $sortBy = null,
        ?int $limit = null,
    ): array {
        $account = $this->resolveAccount($accountKey);

        return $this->apiGet($account, '/v1/designs', [
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
    public function getDesign(?string $accountKey, string $designId): array
    {
        $account = $this->resolveAccount($accountKey);

        return $this->apiGet($account, '/v1/designs/' . rawurlencode($designId));
    }

    /**
     * List the pages (with thumbnails) of a design.
     *
     * @return array<string, mixed>
     */
    public function getDesignPages(
        ?string $accountKey,
        string $designId,
        ?int $offset = null,
        ?int $limit = null,
    ): array {
        $account = $this->resolveAccount($accountKey);

        return $this->apiGet($account, '/v1/designs/' . rawurlencode($designId) . '/pages', [
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────

    private function resolveAccount(?string $accountKey): CanvaAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if ($accounts === []) {
            throw new \RuntimeException('No Canva accounts configured for this server.');
        }

        return reset($accounts);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    private function apiGet(CanvaAccountConfig $account, string $path, array $query = []): array
    {
        $token = $this->validAccessToken($account);

        $response = $this->httpClient->request('GET', $account->apiBaseUrl . $path, [
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

    private function validAccessToken(CanvaAccountConfig $account): string
    {
        if ($account->isAccessTokenValid()) {
            return $account->accessToken;
        }

        if ($account->refreshToken === '') {
            throw new \RuntimeException(sprintf(
                'Canva account "%s" is not connected. Open the Canva page in the Prism admin for this server and click "Connect".',
                $account->key,
            ));
        }

        return $this->refreshAccessToken($account)->accessToken;
    }

    private function refreshAccessToken(CanvaAccountConfig $account): CanvaAccountConfig
    {
        $data = $this->tokenRequest($account, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refreshToken,
        ]);

        return $this->storeTokenResponse($account, $data);
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function tokenRequest(CanvaAccountConfig $account, array $body): array
    {
        if (!$account->hasCredentials()) {
            throw new \RuntimeException(sprintf(
                'Canva account "%s" is missing client_id / client_secret in prism.config.yaml.',
                $account->key,
            ));
        }

        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($account->clientId . ':' . $account->clientSecret),
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
    private function storeTokenResponse(CanvaAccountConfig $account, array $data): CanvaAccountConfig
    {
        $accessToken = (string) $data['access_token'];
        $refreshToken = (string) ($data['refresh_token'] ?? $account->refreshToken);
        $expiresIn = (int) ($data['expires_in'] ?? 0);
        $expiresAt = $expiresIn > 0 ? time() + $expiresIn : 0;

        $this->tokenStore->persistTokens(
            $this->serverContext->getServerName(),
            $account->key,
            $accessToken,
            $refreshToken,
            $expiresAt,
        );

        return $account->withTokens($accessToken, $refreshToken, $expiresAt);
    }
}
