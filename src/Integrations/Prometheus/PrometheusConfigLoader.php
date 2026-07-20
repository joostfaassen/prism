<?php

namespace App\Integrations\Prometheus;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class PrometheusConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, PrometheusProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('prometheus', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new PrometheusProfileConfig(
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

    public function getProfile(string $key): PrometheusProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Prometheus profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
