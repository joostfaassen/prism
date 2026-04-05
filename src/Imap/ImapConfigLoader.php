<?php

namespace App\Imap;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class ImapConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, ImapAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('imap', $this->serverContext);
        $accounts = [];

        foreach ($raw as $id => $cfg) {
            $accounts[$id] = new ImapAccountConfig(
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

        return $accounts;
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
