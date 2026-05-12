<?php

namespace App\Habits;

class HabitsAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $timezone,
        /** Bearer token for /api/habits/{server}/… JSON ingest (optional) */
        public readonly ?string $restIngestToken = null,
    ) {
    }
}
