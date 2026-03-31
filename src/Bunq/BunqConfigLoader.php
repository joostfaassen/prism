<?php

namespace App\Bunq;

use Symfony\Component\Yaml\Yaml;

class BunqConfigLoader
{
    /** @var array<string, BunqAccountConfig>|null */
    private ?array $accounts = null;
    private ?string $apiKey = null;
    private ?string $environment = null;

    public function __construct(
        private readonly string $configPath,
    ) {
    }

    public function getApiKey(): string
    {
        $this->load();

        if ($this->apiKey === null || $this->apiKey === '' || $this->apiKey === 'your-bunq-api-key-here') {
            throw new \RuntimeException('bunq API key not configured in joost-bridge.config.yaml');
        }

        return $this->apiKey;
    }

    public function getEnvironment(): string
    {
        $this->load();

        return $this->environment ?? 'production';
    }

    public function getContextFilePath(): string
    {
        $dir = dirname($this->configPath) . '/var/bunq';

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir . '/context.conf';
    }

    /**
     * @return array<string, BunqAccountConfig>
     */
    public function getAccounts(): array
    {
        $this->load();

        return $this->accounts;
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
     * Resolve an accounts parameter to a list of account keys.
     * Accepts: "*" for all, "key1,key2" for CSV, or a single key.
     *
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

    private function load(): void
    {
        if ($this->accounts !== null) {
            return;
        }

        if (!file_exists($this->configPath)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $this->configPath));
        }

        $config = Yaml::parseFile($this->configPath);
        $bunq = $config['bunq'] ?? [];

        $this->apiKey = $bunq['api_key'] ?? null;
        $this->environment = $bunq['environment'] ?? 'production';
        $this->accounts = [];

        foreach (($bunq['accounts'] ?? []) as $key => $cfg) {
            $this->accounts[$key] = new BunqAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                monetaryAccountId: $cfg['monetary_account_id'] ?? null,
            );
        }
    }
}
