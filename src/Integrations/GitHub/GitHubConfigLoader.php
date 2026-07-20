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
     * @return array<string, GitHubAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('github', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $defaultLogin = $cfg['default_login'] ?? $cfg['login'] ?? null;

            $accounts[$key] = new GitHubAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                token: $cfg['token'] ?? '',
                baseUrl: rtrim($cfg['base_url'] ?? 'https://api.github.com', '/'),
                defaultLogin: $defaultLogin !== null ? (string) $defaultLogin : null,
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): GitHubAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown GitHub account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
