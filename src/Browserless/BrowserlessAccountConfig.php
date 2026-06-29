<?php

namespace App\Browserless;

class BrowserlessAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $token,
        public readonly int $timeout = 120,
    ) {
    }

    public function hasCredentials(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }
}
