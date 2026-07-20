<?php

namespace App\Tmdb;

class TmdbAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $apiKey,
        public readonly string $language = 'en-US',
        /** User session id for account writes (rate, watchlist). */
        public readonly string $sessionId = '',
        /** Optional TMDb account id; resolved via GET /account when empty. */
        public readonly ?int $accountId = null,
    ) {
    }

    public function hasSession(): bool
    {
        return $this->sessionId !== '';
    }
}
