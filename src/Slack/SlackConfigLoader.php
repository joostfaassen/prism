<?php

namespace App\Slack;

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
     * @return array<string, SlackAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('slack', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new SlackAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                token: $cfg['token'] ?? '',
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): SlackAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Slack account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
