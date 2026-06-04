<?php

namespace App\Freescout;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class FreescoutConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, FreescoutAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('freescout', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new FreescoutAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                apiKey: $cfg['api_key'] ?? '',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): FreescoutAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Freescout account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
