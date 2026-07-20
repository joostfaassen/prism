<?php

namespace App\Integrations\Canva;

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
     * @return array<string, CanvaProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('canva', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $scopes = $cfg['scopes'] ?? self::DEFAULT_SCOPES;
            if (!is_array($scopes)) {
                $scopes = self::DEFAULT_SCOPES;
            }

            $profiles[$key] = new CanvaProfileConfig(
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

        return $profiles;
    }

    public function getProfile(string $key): CanvaProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Canva profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)) ?: '(none)',
            ));
        }

        return $profiles[$key];
    }
}
