<?php

namespace App\Bunq;

class BunqAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?int $monetaryAccountId = null,
    ) {
    }
}
