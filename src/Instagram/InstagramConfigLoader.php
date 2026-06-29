<?php

namespace App\Instagram;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class InstagramConfigLoader
{
    /**
     * Default Meta Graph API version. Meta ships a new version each quarter and
     * supports each for roughly two years; override per-account with api_version.
     */
    public const DEFAULT_API_VERSION = 'v22.0';

    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, InstagramAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('instagram', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $version = trim((string) ($cfg['api_version'] ?? self::DEFAULT_API_VERSION));
            if ($version === '') {
                $version = self::DEFAULT_API_VERSION;
            }
            if ($version[0] !== 'v') {
                $version = 'v' . $version;
            }

            $accounts[$key] = new InstagramAccountConfig(
                key: $key,
                label: (string) ($cfg['label'] ?? $key),
                igUserId: trim((string) ($cfg['ig_user_id'] ?? '')),
                accessToken: trim((string) ($cfg['access_token'] ?? '')),
                apiVersion: $version,
                appId: trim((string) ($cfg['app_id'] ?? '')),
                appSecret: trim((string) ($cfg['app_secret'] ?? '')),
                username: trim((string) ($cfg['username'] ?? '')),
                tokenExpiresAt: (int) ($cfg['token_expires_at'] ?? 0),
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): InstagramAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Instagram account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)) ?: '(none)',
            ));
        }

        return $accounts[$key];
    }
}
