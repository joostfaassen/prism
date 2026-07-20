<?php

namespace App\Integrations\N8n;

class N8nProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiKey,
    ) {
    }
}
