<?php

namespace App\Ga4;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class Ga4ConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, Ga4AccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('ga4', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = $this->buildAccountConfig($key, $cfg);
        }

        return $accounts;
    }

    public function getAccount(string $key): Ga4AccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown GA4 account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildAccountConfig(string $key, array $cfg): Ga4AccountConfig
    {
        $propertyId = $cfg['property_id'] ?? null;
        $credentials = $this->resolveCredentials($cfg);

        return new Ga4AccountConfig(
            key: $key,
            label: $cfg['label'] ?? $key,
            defaultPropertyId: $propertyId !== null ? (string) $propertyId : null,
            clientEmail: $credentials['client_email'],
            privateKey: $credentials['private_key'],
            tokenUri: $credentials['token_uri'],
        );
    }

    /**
     * Resolve service-account credentials either from inline `client_email` +
     * `private_key`, or from a Google service-account JSON key referenced by
     * `credentials_file`. File paths may be absolute or relative to the project
     * directory (same rule as TransipConfigLoader::resolvePrivateKey()).
     *
     * @param array<string, mixed> $cfg
     *
     * @return array{client_email: string, private_key: string, token_uri: string}
     */
    private function resolveCredentials(array $cfg): array
    {
        $inlineEmail = $cfg['client_email'] ?? null;
        $inlineKey = $cfg['private_key'] ?? null;

        if (is_string($inlineEmail) && trim($inlineEmail) !== '' && is_string($inlineKey) && trim($inlineKey) !== '') {
            return [
                'client_email' => $inlineEmail,
                'private_key' => $inlineKey,
                'token_uri' => is_string($cfg['token_uri'] ?? null) && $cfg['token_uri'] !== ''
                    ? $cfg['token_uri']
                    : 'https://oauth2.googleapis.com/token',
            ];
        }

        $file = $cfg['credentials_file'] ?? null;
        if (is_string($file) && $file !== '') {
            $path = $this->isAbsolutePath($file) ? $file : $this->projectDir . '/' . ltrim($file, '/');

            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('GA4 credentials file not found: %s', $path));
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Unable to read GA4 credentials file: %s', $path));
            }

            $data = json_decode($contents, true);
            if (!is_array($data)) {
                throw new \RuntimeException(sprintf('GA4 credentials file is not valid JSON: %s', $path));
            }

            return [
                'client_email' => is_string($data['client_email'] ?? null) ? $data['client_email'] : '',
                'private_key' => is_string($data['private_key'] ?? null) ? $data['private_key'] : '',
                'token_uri' => is_string($data['token_uri'] ?? null) && $data['token_uri'] !== ''
                    ? $data['token_uri']
                    : 'https://oauth2.googleapis.com/token',
            ];
        }

        return [
            'client_email' => '',
            'private_key' => '',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
