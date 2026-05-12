<?php

namespace App\Habits;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class HabitsConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /** @return array<string, HabitsAccountConfig> */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('habits', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $token = $cfg['rest_ingest_token'] ?? $cfg['rest_token'] ?? null;
            $accounts[$key] = new HabitsAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                timezone: $cfg['timezone'] ?? 'UTC',
                restIngestToken: is_string($token) && $token !== '' ? $token : null,
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): HabitsAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Habits account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }

    public function getTimezone(): \DateTimeZone
    {
        $accounts = $this->getAccounts();
        $first = reset($accounts);

        return new \DateTimeZone($first ? $first->timezone : 'UTC');
    }
}
