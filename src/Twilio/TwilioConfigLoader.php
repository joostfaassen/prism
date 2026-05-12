<?php

namespace App\Twilio;

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
     * @return array<string, TwilioAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('twilio', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new TwilioAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                accountSid: $cfg['account_sid'] ?? '',
                authToken: $cfg['auth_token'] ?? '',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): TwilioAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Twilio account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
