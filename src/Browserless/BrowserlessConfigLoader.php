<?php

namespace App\Browserless;

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
     * @return array<string, BrowserlessAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('browserless', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $baseUrl = rtrim(trim((string) ($cfg['base_url'] ?? '')), '/');

            $timeout = (int) ($cfg['timeout'] ?? 120);
            if ($timeout <= 0) {
                $timeout = 120;
            }

            $accounts[$key] = new BrowserlessAccountConfig(
                key: $key,
                label: (string) ($cfg['label'] ?? $key),
                baseUrl: $baseUrl,
                // Accept either `token` (browserless' own naming) or the more
                // generic `bearer_token` used elsewhere in Prism configs.
                token: trim((string) ($cfg['token'] ?? $cfg['bearer_token'] ?? '')),
                timeout: $timeout,
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): BrowserlessAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Browserless account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)) ?: '(none)',
            ));
        }

        return $accounts[$key];
    }
}
