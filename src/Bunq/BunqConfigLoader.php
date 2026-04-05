<?php

namespace App\Bunq;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class BunqConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, BunqAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('bunq', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = $this->buildAccountConfig($key, $cfg);
        }

        return $accounts;
    }

    public function getAccount(string $key): BunqAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            $available = implode(', ', array_keys($accounts));
            throw new \InvalidArgumentException(sprintf(
                'Unknown bunq account: "%s". Available: %s',
                $key,
                $available,
            ));
        }

        return $accounts[$key];
    }

    /**
     * @return list<string>
     */
    public function resolveAccountKeys(string $accountsParam): array
    {
        if ($accountsParam === '*') {
            return array_keys($this->getAccounts());
        }

        $keys = array_map('trim', explode(',', $accountsParam));
        $keys = array_filter($keys, fn(string $k) => $k !== '');

        foreach ($keys as $key) {
            $this->getAccount($key);
        }

        return array_values($keys);
    }

    /**
     * Context files are keyed by API key hash so accounts sharing the same
     * API key reuse a single bunq session.
     */
    public function getContextFilePath(string $apiKey): string
    {
        $hash = substr(md5($apiKey), 0, 8);
        $dir = $this->projectDir . '/var/bunq';

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir . '/context-' . $hash . '.conf';
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildAccountConfig(string $key, array $cfg): BunqAccountConfig
    {
        return new BunqAccountConfig(
            key: $key,
            label: $cfg['label'] ?? $key,
            apiKey: $cfg['api_key'] ?? '',
            environment: $cfg['environment'] ?? 'production',
            monetaryAccountId: $cfg['monetary_account_id'] ?? null,
        );
    }
}
