<?php

namespace App\Integrations\Habits;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class HabitsConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /** @return array<string, HabitsProfileConfig> */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('habits', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $token = $cfg['rest_ingest_token'] ?? $cfg['rest_token'] ?? null;
            $profiles[$key] = new HabitsProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                timezone: $cfg['timezone'] ?? 'UTC',
                restIngestToken: is_string($token) && $token !== '' ? $token : null,
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): HabitsProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Habits profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }

    public function getTimezone(): \DateTimeZone
    {
        $profiles = $this->getProfiles();
        $first = reset($profiles);

        return new \DateTimeZone($first ? $first->timezone : 'UTC');
    }
}
