<?php

namespace App\N8n;

class N8nAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiKey,
    ) {
    }
}
