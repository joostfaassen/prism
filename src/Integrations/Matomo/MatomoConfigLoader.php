<?php

namespace App\Integrations\Matomo;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class MatomoConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, MatomoProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('matomo', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $defaultIdSite = $cfg['default_id_site'] ?? null;

            $profiles[$key] = new MatomoProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                tokenAuth: $cfg['token_auth'] ?? '',
                defaultIdSite: $defaultIdSite !== null ? (int) $defaultIdSite : null,
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): MatomoProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Matomo profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
