<?php

namespace App\Integrations\Ga4;

class Ga4ProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $defaultPropertyId,
        public readonly string $clientEmail,
        public readonly string $privateKey,
        public readonly string $tokenUri = 'https://oauth2.googleapis.com/token',
    ) {
    }
}
