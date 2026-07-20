<?php

namespace App\Integrations\Tracking;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class TrackingConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /** @return array<string, TrackingProfileConfig> */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('tracking', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new TrackingProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                timezone: $cfg['timezone'] ?? 'UTC',
            );
        }

        return $profiles;
    }

    public function getTimezone(): \DateTimeZone
    {
        $profiles = $this->getProfiles();
        $first = reset($profiles);

        return new \DateTimeZone($first ? $first->timezone : 'UTC');
    }
}
