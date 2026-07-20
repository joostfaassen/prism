<?php

namespace App\Integrations\Slack;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class SlackConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, SlackProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('slack', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new SlackProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                token: $cfg['token'] ?? '',
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): SlackProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Slack profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
