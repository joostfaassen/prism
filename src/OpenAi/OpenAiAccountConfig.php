<?php

namespace App\OpenAi;

class OpenAiAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly string $defaultModel,
        public readonly int $timeout = 60,
    ) {
    }
}
