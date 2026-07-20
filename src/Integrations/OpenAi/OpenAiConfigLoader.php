<?php

namespace App\Integrations\OpenAi;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class OpenAiConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, OpenAiProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('openai', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $timeout = $cfg['timeout'] ?? 60;

            $profiles[$key] = new OpenAiProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                apiKey: $cfg['api_key'] ?? '',
                defaultModel: $cfg['default_model'] ?? '',
                timeout: (int) $timeout,
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): OpenAiProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown OpenAI profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
