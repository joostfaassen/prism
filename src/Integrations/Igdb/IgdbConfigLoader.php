<?php

namespace App\Integrations\Igdb;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class IgdbConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, IgdbAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('igdb', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new IgdbAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                clientId: $cfg['client_id'] ?? '',
                clientSecret: $cfg['client_secret'] ?? '',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): IgdbAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown IGDB account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
