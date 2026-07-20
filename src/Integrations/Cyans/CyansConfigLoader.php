<?php

namespace App\Integrations\Cyans;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class CyansConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, CyansProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('cyans', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new CyansProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                dsn: $cfg['dsn'] ?? '',
                username: $cfg['username'] ?? '',
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): CyansProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            $available = implode(', ', array_keys($profiles));
            throw new \InvalidArgumentException(sprintf(
                'Unknown Cyans profile: "%s". Available: %s',
                $key,
                $available,
            ));
        }

        return $profiles[$key];
    }
}
