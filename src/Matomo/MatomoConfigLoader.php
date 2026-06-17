<?php

namespace App\Matomo;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class MatomoConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, MatomoAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('matomo', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $defaultIdSite = $cfg['default_id_site'] ?? null;

            $accounts[$key] = new MatomoAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                tokenAuth: $cfg['token_auth'] ?? '',
                defaultIdSite: $defaultIdSite !== null ? (int) $defaultIdSite : null,
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): MatomoAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Matomo account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
