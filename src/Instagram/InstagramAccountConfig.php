<?php

namespace App\Instagram;

class InstagramAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $igUserId,
        public readonly string $accessToken,
        public readonly string $apiVersion,
        public readonly string $appId = '',
        public readonly string $appSecret = '',
        public readonly string $username = '',
        public readonly int $tokenExpiresAt = 0,
    ) {
    }

    public function hasCredentials(): bool
    {
        return $this->igUserId !== '' && $this->accessToken !== '';
    }

    public function canRefreshToken(): bool
    {
        return $this->appId !== '' && $this->appSecret !== '' && $this->accessToken !== '';
    }

    /**
     * Days until the configured long-lived token expires, or null if unknown.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->tokenExpiresAt <= 0) {
            return null;
        }

        return (int) floor(($this->tokenExpiresAt - time()) / 86400);
    }

    public function withToken(string $accessToken, int $tokenExpiresAt): self
    {
        return new self(
            key: $this->key,
            label: $this->label,
            igUserId: $this->igUserId,
            accessToken: $accessToken,
            apiVersion: $this->apiVersion,
            appId: $this->appId,
            appSecret: $this->appSecret,
            username: $this->username,
            tokenExpiresAt: $tokenExpiresAt,
        );
    }
}
