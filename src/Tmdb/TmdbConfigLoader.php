<?php

namespace App\Tmdb;

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
     * @return array<string, TmdbAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('tmdb', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accountId = $cfg['account_id'] ?? null;

            $accounts[$key] = new TmdbAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                apiKey: $cfg['api_key'] ?? '',
                language: $cfg['language'] ?? 'en-US',
                sessionId: (string) ($cfg['session_id'] ?? ''),
                accountId: $accountId !== null && $accountId !== '' ? (int) $accountId : null,
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): TmdbAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown TMDb account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
