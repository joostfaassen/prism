<?php

namespace App\Integrations\Igdb;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class IgdbConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, IgdbProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('igdb', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new IgdbProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                clientId: $cfg['client_id'] ?? '',
                clientSecret: $cfg['client_secret'] ?? '',
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): IgdbProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown IGDB profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
