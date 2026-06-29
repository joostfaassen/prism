<?php

namespace App\Canva;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class CanvaConfigLoader
{
    /**
     * Scopes required to list and view designs and their pages.
     *
     * @var list<string>
     */
    public const DEFAULT_SCOPES = ['design:meta:read', 'design:content:read'];

    public const DEFAULT_API_BASE_URL = 'https://api.canva.com/rest';

    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, CanvaAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('canva', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $scopes = $cfg['scopes'] ?? self::DEFAULT_SCOPES;
            if (!is_array($scopes)) {
                $scopes = self::DEFAULT_SCOPES;
            }

            $accounts[$key] = new CanvaAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                clientId: (string) ($cfg['client_id'] ?? ''),
                clientSecret: (string) ($cfg['client_secret'] ?? ''),
                accessToken: (string) ($cfg['access_token'] ?? ''),
                refreshToken: (string) ($cfg['refresh_token'] ?? ''),
                tokenExpiresAt: (int) ($cfg['token_expires_at'] ?? 0),
                apiBaseUrl: rtrim((string) ($cfg['api_base_url'] ?? self::DEFAULT_API_BASE_URL), '/'),
                scopes: array_values(array_map('strval', $scopes)),
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): CanvaAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Canva account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)) ?: '(none)',
            ));
        }

        return $accounts[$key];
    }
}
