<?php

namespace App\Cyans;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class CyansConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, CyansAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('cyans', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new CyansAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                dsn: $cfg['dsn'] ?? '',
                username: $cfg['username'] ?? '',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): CyansAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            $available = implode(', ', array_keys($accounts));
            throw new \InvalidArgumentException(sprintf(
                'Unknown Cyans account: "%s". Available: %s',
                $key,
                $available,
            ));
        }

        return $accounts[$key];
    }
}
