<?php

namespace App\Integrations\Transip;

class TransipProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $login,
        public readonly string $privateKey,
        public readonly bool $readOnly = false,
        public readonly bool $globalKey = true,
    ) {
    }
}
