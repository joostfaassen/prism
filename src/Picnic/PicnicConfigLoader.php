<?php

namespace App\Picnic;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class PicnicConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, PicnicAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('picnic', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new PicnicAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                username: $cfg['username'] ?? '',
                password: $cfg['password'] ?? '',
                countryCode: strtolower($cfg['country_code'] ?? 'nl'),
                apiVersion: (string) ($cfg['api_version'] ?? '15'),
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): PicnicAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            $available = implode(', ', array_keys($accounts));
            throw new \InvalidArgumentException(sprintf(
                'Unknown Picnic account: "%s". Available: %s',
                $key,
                $available,
            ));
        }

        return $accounts[$key];
    }

    /**
     * Cached auth token file, keyed by a hash of username to survive across requests.
     */
    public function getTokenFilePath(string $username): string
    {
        $hash = substr(md5($username), 0, 8);
        $dir = $this->projectDir . '/var/picnic';

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir . '/token-' . $hash . '.txt';
    }
}
