<?php

namespace App\OpenAi;

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
     * @return array<string, OpenAiAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('openai', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $timeout = $cfg['timeout'] ?? 60;

            $accounts[$key] = new OpenAiAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                baseUrl: rtrim($cfg['base_url'] ?? '', '/'),
                apiKey: $cfg['api_key'] ?? '',
                defaultModel: $cfg['default_model'] ?? '',
                timeout: (int) $timeout,
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): OpenAiAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown OpenAI account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }
}
