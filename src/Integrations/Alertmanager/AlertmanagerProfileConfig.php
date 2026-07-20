<?php

namespace App\Integrations\Alertmanager;

class AlertmanagerProfileConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly string $bearerToken = '',
        public readonly string $username = '',
        public readonly string $password = '',
    ) {
    }
}
