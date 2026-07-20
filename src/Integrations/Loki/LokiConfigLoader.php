<?php

namespace App\Integrations\Loki;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class LokiConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, LokiProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('loki', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new LokiProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                bearerToken: $cfg['bearer_token'] ?? '',
                username: $cfg['username'] ?? '',
                password: $cfg['password'] ?? '',
                orgId: $cfg['org_id'] ?? '',
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): LokiProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Loki profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
