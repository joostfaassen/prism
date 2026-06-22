<?php

namespace App\SendGrid;

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
     * @return array<string, SendGridAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('sendgrid', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $baseUrl = $cfg['base_url'] ?? 'https://api.sendgrid.com';

            $accounts[$key] = new SendGridAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                apiKey: $cfg['api_key'] ?? '',
                baseUrl: rtrim($baseUrl, '/'),
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): SendGridAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown SendGrid account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
