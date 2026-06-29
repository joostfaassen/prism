<?php

namespace App\Transip;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class TransipConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, TransipAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('transip', $this->serverContext);
        $accounts = [];

        foreach ($raw as $key => $cfg) {
            $accounts[$key] = new TransipAccountConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                login: $cfg['login'] ?? '',
                privateKey: $this->resolvePrivateKey($cfg),
                readOnly: (bool) ($cfg['read_only'] ?? false),
                globalKey: (bool) ($cfg['global_key'] ?? true),
            );
        }

        return $accounts;
    }

    public function getAccount(string $key): TransipAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown TransIP account: "%s". Available: %s',
                $key,
                implode(', ', array_keys($accounts)),
            ));
        }

        return $accounts[$key];
    }

    /**
     * Resolve the RSA private key either from an inline PEM string
     * (`private_key`) or from a file on disk (`private_key_file`). File paths
     * may be absolute or relative to the project directory.
     *
     * @param array<string, mixed> $cfg
     */
    private function resolvePrivateKey(array $cfg): string
    {
        $inline = $cfg['private_key'] ?? null;
        if (is_string($inline) && trim($inline) !== '') {
            return $inline;
        }

        $file = $cfg['private_key_file'] ?? null;
        if (is_string($file) && $file !== '') {
            $path = $this->isAbsolutePath($file) ? $file : $this->projectDir . '/' . ltrim($file, '/');

            if (!is_file($path)) {
                throw new \RuntimeException(sprintf('TransIP private key file not found: %s', $path));
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Unable to read TransIP private key file: %s', $path));
            }

            return $contents;
        }

        return '';
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
