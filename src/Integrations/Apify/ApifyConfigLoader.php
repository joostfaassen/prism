<?php

namespace App\Integrations\Apify;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class ApifyConfigLoader
{
    private const DEFAULT_BASE_URL = 'https://api.apify.com/v2';

    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, ApifyProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('apify', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $baseUrl = rtrim($cfg['base_url'] ?? self::DEFAULT_BASE_URL, '/');
            if ($baseUrl === '') {
                $baseUrl = self::DEFAULT_BASE_URL;
            }

            $profiles[$key] = new ApifyProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: $baseUrl,
                // Accept either `api_token` (Apify's own naming) or the more
                // generic `bearer_token` used elsewhere in Prism configs.
                apiToken: $cfg['api_token'] ?? $cfg['bearer_token'] ?? '',
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): ApifyProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Apify profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
