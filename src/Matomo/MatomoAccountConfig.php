<?php

namespace App\Matomo;

class MatomoAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $tokenAuth,
        public readonly ?int $defaultIdSite = null,
    ) {
    }
}
