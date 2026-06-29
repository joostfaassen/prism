<?php

namespace App\Canva;

class CanvaAccountConfig
{
    /**
     * @param list<string> $scopes OAuth scopes requested during authorization
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $tokenExpiresAt,
        public readonly string $apiBaseUrl,
        public readonly array $scopes,
    ) {
    }

    public function hasCredentials(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function isConnected(): bool
    {
        return $this->refreshToken !== '' || $this->accessToken !== '';
    }

    public function isAccessTokenValid(): bool
    {
        return $this->accessToken !== '' && $this->tokenExpiresAt > time() + 60;
    }

    public function withTokens(string $accessToken, string $refreshToken, int $tokenExpiresAt): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            tokenExpiresAt: $tokenExpiresAt,
            apiBaseUrl: $this->apiBaseUrl,
            scopes: $this->scopes,
        );
    }
}
