<?php

namespace App\Integrations\Picnic;

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
     * @return array<string, PicnicProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('picnic', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $authKey = $cfg['auth_key'] ?? null;
            $profiles[$key] = new PicnicProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                username: $cfg['username'] ?? '',
                password: $cfg['password'] ?? '',
                countryCode: strtolower($cfg['country_code'] ?? 'nl'),
                apiVersion: (string) ($cfg['api_version'] ?? '15'),
                authKey: is_string($authKey) && $authKey !== '' ? $authKey : null,
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): PicnicProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Picnic profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
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
