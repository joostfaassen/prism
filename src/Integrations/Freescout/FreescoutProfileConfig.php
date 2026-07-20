<?php

namespace App\Integrations\Freescout;

class FreescoutProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiKey,
    ) {
    }
}
