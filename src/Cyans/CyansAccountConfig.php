<?php

namespace App\Cyans;

class CyansAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $dsn,
        public readonly string $username,
    ) {
    }
}
