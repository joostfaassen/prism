<?php

namespace App\Integrations\Slack;

class SlackProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $token,
    ) {
    }
}
