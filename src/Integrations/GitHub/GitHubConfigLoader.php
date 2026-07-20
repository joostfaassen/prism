<?php

namespace App\Integrations\GitHub;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class GitHubConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, GitHubProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('github', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $defaultLogin = $cfg['default_login'] ?? $cfg['login'] ?? null;

            $profiles[$key] = new GitHubProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                token: $cfg['token'] ?? '',
                baseUrl: rtrim($cfg['base_url'] ?? 'https://api.github.com', '/'),
                defaultLogin: $defaultLogin !== null ? (string) $defaultLogin : null,
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): GitHubProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown GitHub profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
