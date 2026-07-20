<?php

namespace App\Integrations\Calendar;

class CalendarConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $icsUrl,
        public readonly ?string $summary = null,
    ) {
    }
}
