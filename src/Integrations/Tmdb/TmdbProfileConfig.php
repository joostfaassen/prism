<?php

namespace App\Integrations\Tmdb;

class TmdbProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $apiKey,
        public readonly string $language = 'en-US',
        /** User session id for profile writes (rate, watchlist). */
        public readonly string $sessionId = '',
        /** Optional TMDb profile id; resolved via GET /profile when empty. */
        public readonly ?int $profileId = null,
    ) {
    }

    public function hasSession(): bool
    {
        return $this->sessionId !== '';
    }
}
