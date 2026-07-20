<?php

namespace App\Integrations\Alertmanager;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class AlertmanagerConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, AlertmanagerProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('alertmanager', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new AlertmanagerProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                bearerToken: $cfg['bearer_token'] ?? '',
                username: $cfg['username'] ?? '',
                password: $cfg['password'] ?? '',
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): AlertmanagerProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Alertmanager profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
