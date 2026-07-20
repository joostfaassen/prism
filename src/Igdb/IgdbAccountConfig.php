<?php

namespace App\Igdb;

class IgdbAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $clientId,
        public readonly string $clientSecret,
    ) {
    }
}
