<?php

namespace App\Integrations\Browserless;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class BrowserlessConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, BrowserlessProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('browserless', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $baseUrl = rtrim(trim((string) ($cfg['base_url'] ?? '')), '/');

            $timeout = (int) ($cfg['timeout'] ?? 120);
            if ($timeout <= 0) {
                $timeout = 120;
            }

            $profiles[$key] = new BrowserlessProfileConfig(
                key: $key,
                label: (string) ($cfg['label'] ?? $key),
                baseUrl: $baseUrl,
                // Accept either `token` (browserless' own naming) or the more
                // generic `bearer_token` used elsewhere in Prism configs.
                token: trim((string) ($cfg['token'] ?? $cfg['bearer_token'] ?? '')),
                timeout: $timeout,
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): BrowserlessProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Browserless profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)) ?: '(none)',
            ));
        }

        return $profiles[$key];
    }
}
