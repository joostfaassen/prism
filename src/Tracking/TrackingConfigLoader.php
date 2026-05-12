<?php

namespace App\Tracking;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class TrackingConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /** @return array<string, TrackingAccountConfig> */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('tracking', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new TrackingAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                timezone: $cfg['timezone'] ?? 'UTC',
            );
        }

        return $accounts;
    }

    public function getTimezone(): \DateTimeZone
    {
        $accounts = $this->getAccounts();
        $first = reset($accounts);

        return new \DateTimeZone($first ? $first->timezone : 'UTC');
    }
}
