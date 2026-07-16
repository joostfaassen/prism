<?php

namespace App\GitHub;

class GitHubAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $token,
        public readonly string $baseUrl = 'https://api.github.com',
        public readonly ?string $defaultLogin = null,
    ) {
    }
}
