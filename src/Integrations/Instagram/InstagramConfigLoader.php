<?php

namespace App\Integrations\Instagram;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class InstagramConfigLoader
{
    /**
     * Default Meta Graph API version. Meta ships a new version each quarter and
     * supports each for roughly two years; override per-profile with api_version.
     */
    public const DEFAULT_API_VERSION = 'v22.0';

    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, InstagramProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('instagram', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $version = trim((string) ($cfg['api_version'] ?? self::DEFAULT_API_VERSION));
            if ($version === '') {
                $version = self::DEFAULT_API_VERSION;
            }
            if ($version[0] !== 'v') {
                $version = 'v' . $version;
            }

            $profiles[$key] = new InstagramProfileConfig(
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

        return $profiles;
    }

    public function getProfile(string $key): InstagramProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Instagram profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)) ?: '(none)',
            ));
        }

        return $profiles[$key];
    }
}
