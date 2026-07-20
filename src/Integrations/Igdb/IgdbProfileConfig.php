<?php

namespace App\Integrations\Igdb;

class IgdbProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $clientId,
        public readonly string $clientSecret,
    ) {
    }
}
