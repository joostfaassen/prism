<?php

namespace App\Integrations\Libredesk;

class LibredeskAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly string $apiSecret,
    ) {
    }
}
