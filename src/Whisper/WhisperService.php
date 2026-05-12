<?php

namespace App\Whisper;

use App\Config\PrismConfigLoader;

class WhisperService
{
    /** @var array<string, WhisperProviderInterface> */
    private array $providerMap = [];

    /**
     * @param iterable<WhisperProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly PrismConfigLoader $configLoader,
    ) {
    }

    public function transcribe(string $audioFilePath, ?string $language = null): TranscriptionResult
    {
        if (!file_exists($audioFilePath)) {
            throw new \InvalidArgumentException(sprintf('Audio file not found: %s', $audioFilePath));
        }

        $config = $this->configLoader->getWhisperConfig();
        $providerName = $config['provider'] ?? 'openai';
        $providerConfig = $config[$providerName] ?? [];

        $provider = $this->resolveProvider($providerName);

        return $provider->transcribe($audioFilePath, $providerConfig, $language);
    }

    public function isConfigured(): bool
    {
        $config = $this->configLoader->getWhisperConfig();

        return !empty($config['provider']);
    }

    public function getProviderName(): string
    {
        $config = $this->configLoader->getWhisperConfig();

        return $config['provider'] ?? 'openai';
    }

    private function resolveProvider(string $name): WhisperProviderInterface
    {
        if (isset($this->providerMap[$name])) {
            return $this->providerMap[$name];
        }

        if (empty($this->providerMap)) {
            foreach ($this->providers as $provider) {
                $this->providerMap[$provider->getName()] = $provider;
            }
        }

        if (!isset($this->providerMap[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Whisper provider: "%s". Available: %s',
                $name,
                implode(', ', array_keys($this->providerMap)),
            ));
        }

        return $this->providerMap[$name];
    }
}
