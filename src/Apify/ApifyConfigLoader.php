<?php

namespace App\Apify;

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
     * @return array<string, ApifyAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('apify', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $baseUrl = rtrim($cfg['base_url'] ?? self::DEFAULT_BASE_URL, '/');
            if ($baseUrl === '') {
                $baseUrl = self::DEFAULT_BASE_URL;
            }

            $accounts[$key] = new ApifyAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: $baseUrl,
                // Accept either `api_token` (Apify's own naming) or the more
                // generic `bearer_token` used elsewhere in Prism configs.
                apiToken: $cfg['api_token'] ?? $cfg['bearer_token'] ?? '',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): ApifyAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Apify account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
