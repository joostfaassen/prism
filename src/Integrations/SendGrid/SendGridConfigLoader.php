<?php

namespace App\Integrations\SendGrid;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class SendGridConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, SendGridProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('sendgrid', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $baseUrl = $cfg['base_url'] ?? 'https://api.sendgrid.com';

            $profiles[$key] = new SendGridProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                apiKey: $cfg['api_key'] ?? '',
                baseUrl: rtrim($baseUrl, '/'),
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): SendGridProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown SendGrid profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }
}
