<?php

namespace App\Imap;

use Symfony\Component\Yaml\Yaml;

class ImapConfigLoader
{
    /** @var array<string, ImapAccountConfig>|null */
    private ?array $accounts = null;

    public function __construct(
        private readonly string $configPath,
    ) {
    }

    /**
     * @return array<string, ImapAccountConfig>
     */
    public function getAccounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        if (!file_exists($this->configPath)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $this->configPath));
        }

        $config = Yaml::parseFile($this->configPath);
        $accounts = $config['imap']['accounts'] ?? [];
        $this->accounts = [];

        foreach ($accounts as $id => $cfg) {
            $this->accounts[$id] = new ImapAccountConfig(
                id: $id,
                label: $cfg['label'] ?? $id,
                host: $cfg['host'],
                port: $cfg['port'] ?? 993,
                encryption: $cfg['encryption'] ?? 'ssl',
                username: $cfg['username'],
                password: $cfg['password'],
                validateCert: $cfg['validate_cert'] ?? true,
            );
        }

        return $this->accounts;
    }

    public function getAccount(string $id): ImapAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$id])) {
            throw new \InvalidArgumentException(sprintf('Unknown IMAP account: "%s"', $id));
        }

        return $accounts[$id];
    }
}
