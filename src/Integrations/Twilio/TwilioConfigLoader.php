<?php

namespace App\Integrations\Twilio;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class TwilioConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, TwilioProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('twilio', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $profiles[$key] = new TwilioProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                accountSid: $cfg['account_sid'] ?? '',
                authToken: $cfg['auth_token'] ?? '',
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): TwilioProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Twilio profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
