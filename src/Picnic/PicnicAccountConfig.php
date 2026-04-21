<?php

namespace App\Picnic;

class PicnicAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $username,
        public readonly string $password,
        public readonly string $countryCode = 'nl',
        public readonly string $apiVersion = '15',
    ) {
    }
}
