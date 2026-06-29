<?php

namespace App\Apify;

class ApifyAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiToken,
    ) {
    }
}
