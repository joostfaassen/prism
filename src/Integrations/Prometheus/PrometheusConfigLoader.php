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
     * @return array<string, PrometheusAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('prometheus', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new PrometheusAccountConfig(
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

    public function getAccount(string $key): PrometheusAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Prometheus account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
