<?php

namespace App\Integrations\Bunq;

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
     * @return array<string, BunqProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('bunq', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = $this->buildProfileConfig($key, $cfg);
        }

        return $profiles;
    }

    public function getProfile(string $key): BunqProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            $available = implode(', ', array_keys($profiles));
            throw new \InvalidArgumentException(sprintf(
                'Unknown bunq profile: "%s". Available: %s',
                $key,
                $available,
            ));
        }

        return $profiles[$key];
    }

    /**
     * @return list<string>
     */
    public function resolveProfileKeys(string $profilesParam): array
    {
        if ($profilesParam === '*') {
            return array_keys($this->getProfiles());
        }

        $keys = array_map('trim', explode(',', $profilesParam));
        $keys = array_filter($keys, fn(string $k) => $k !== '');

        foreach ($keys as $key) {
            $this->getProfile($key);
        }

        return array_values($keys);
    }

    /**
     * Context files are keyed by API key hash so profiles sharing the same
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
    private function buildProfileConfig(string $key, array $cfg): BunqProfileConfig
    {
        return new BunqProfileConfig(
            key: $key,
            label: $cfg['label'] ?? $key,
            apiKey: $cfg['api_key'] ?? '',
            environment: $cfg['environment'] ?? 'production',
            monetaryAccountId: $cfg['monetary_account_id'] ?? null,
            configFile: $cfg['config_file'] ?? null,
        );
    }
}
