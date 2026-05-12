<?php

namespace App\Tracking;

class TrackingAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $timezone,
    ) {
    }
}
