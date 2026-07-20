<?php

namespace App\Integrations\Tmdb;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class TmdbConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, TmdbProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('tmdb', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profileId = $cfg['account_id'] ?? null;

            $profiles[$key] = new TmdbProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                apiKey: $cfg['api_key'] ?? '',
                language: $cfg['language'] ?? 'en-US',
                sessionId: (string) ($cfg['session_id'] ?? ''),
                accountId: $profileId !== null && $profileId !== '' ? (int) $profileId : null,
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): TmdbProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown TMDb profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
