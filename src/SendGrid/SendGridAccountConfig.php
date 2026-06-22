<?php

namespace App\SendGrid;

class SendGridAccountConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $apiKey,
        public readonly string $baseUrl = 'https://api.sendgrid.com',
    ) {
    }
}
