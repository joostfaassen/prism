<?php

namespace App\Alertmanager;

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
     * @return array<string, AlertmanagerAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('alertmanager', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new AlertmanagerAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                bearerToken: $cfg['bearer_token'] ?? '',
                username: $cfg['username'] ?? '',
                password: $cfg['password'] ?? '',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): AlertmanagerAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Alertmanager account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
