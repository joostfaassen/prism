<?php

namespace App\Twilio;

class TwilioAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $accountSid,
        public readonly string $authToken,
    ) {
    }
}
