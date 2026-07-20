<?php

namespace App\Integrations\Bunq;

class BunqProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $apiKey,
        public readonly string $environment = 'production',
        public readonly ?int $monetaryAccountId = null,
        public readonly ?string $configFile = null,
    ) {
    }
}
