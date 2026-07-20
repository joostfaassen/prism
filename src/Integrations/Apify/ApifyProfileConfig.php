<?php

namespace App\Integrations\Apify;

class ApifyProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiToken,
    ) {
    }
}
