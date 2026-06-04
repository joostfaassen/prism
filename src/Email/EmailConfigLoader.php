<?php

namespace App\Email;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

/**
 * Loads `email` accounts from the active server's config and inflates them
 * into typed value objects.
 *
 * Account YAML shape:
 *
 *   accounts:
 *     work-mail:
 *       type: email
 *       label: "Work mailbox"
 *       imap:
 *         host: imap.example.com
 *         port: 993
 *         encryption: ssl
 *         username: user@example.com
 *         password: ...
 *         validate_cert: true
 *         sent_folder: "Sent"        # optional, defaults to "Sent"
 *         drafts_folder: "Drafts"    # optional, defaults to "Drafts"
 *       smtp:
 *         host: smtp.example.com
 *         port: 465
 *         encryption: ssl
 *         username: user@example.com  # optional, defaults to imap.username
 *         password: ...               # optional, defaults to imap.password
 *         validate_cert: true
 *       identity:
 *         email: user@example.com     # optional, defaults to imap.username
 *         name: "Display Name"
 *         reply_to: "team@example.com" # optional
 */
class EmailConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, EmailAccountConfig>
     */
    public function getAccounts(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('email', $this->serverContext);
        $accounts = [];

        foreach ($raw as $id => $cfg) {
            $accounts[$id] = $this->buildAccount((string) $id, $cfg);
        }

        return $accounts;
    }

    public function getAccount(string $id): EmailAccountConfig
    {
        $accounts = $this->getAccounts();

        if (!isset($accounts[$id])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown email account: "%s". Available: %s',
                $id,
                implode(', ', array_keys($accounts)) ?: '(none)',
            ));
        }

        return $accounts[$id];
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildAccount(string $id, array $cfg): EmailAccountConfig
    {
        $imapRaw = $this->normalizeImap($cfg);

        if ($imapRaw === null) {
            throw new \InvalidArgumentException(sprintf(
                'Email account "%s" is missing required IMAP configuration.',
                $id,
            ));
        }

        $imap = new ImapConfig(
            host: (string) $imapRaw['host'],
            port: (int) ($imapRaw['port'] ?? 993),
            encryption: (string) ($imapRaw['encryption'] ?? 'ssl'),
            username: (string) $imapRaw['username'],
            password: (string) $imapRaw['password'],
            validateCert: (bool) ($imapRaw['validate_cert'] ?? true),
        );

        $smtp = null;
        $smtpRaw = $cfg['smtp'] ?? null;

        if (is_array($smtpRaw) && isset($smtpRaw['host'])) {
            $smtp = new SmtpConfig(
                host: (string) $smtpRaw['host'],
                port: (int) ($smtpRaw['port'] ?? 587),
                encryption: (string) ($smtpRaw['encryption'] ?? 'starttls'),
                username: (string) ($smtpRaw['username'] ?? $imap->username),
                password: (string) ($smtpRaw['password'] ?? $imap->password),
                validateCert: (bool) ($smtpRaw['validate_cert'] ?? true),
            );
        }

        $identity = null;
        $idRaw = $cfg['identity'] ?? null;

        if (is_array($idRaw)) {
            $identity = new EmailIdentity(
                email: (string) ($idRaw['email'] ?? $imap->username),
                name: isset($idRaw['name']) ? (string) $idRaw['name'] : null,
                replyTo: isset($idRaw['reply_to']) ? (string) $idRaw['reply_to'] : null,
            );
        }

        return new EmailAccountConfig(
            id: $id,
            label: (string) ($cfg['label'] ?? $id),
            imap: $imap,
            smtp: $smtp,
            identity: $identity,
            sentFolder: (string) ($imapRaw['sent_folder'] ?? $cfg['sent_folder'] ?? 'Sent'),
            draftsFolder: (string) ($imapRaw['drafts_folder'] ?? $cfg['drafts_folder'] ?? 'Drafts'),
        );
    }

    /**
     * Accept either a nested {imap: {...}} block or a flat IMAP config (legacy
     * shape, where imap fields lived directly on the account). The flat form
     * is preserved here purely so existing yaml that hasn't been migrated yet
     * keeps working — new configs should use the nested form.
     *
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>|null
     */
    private function normalizeImap(array $cfg): ?array
    {
        if (isset($cfg['imap']) && is_array($cfg['imap'])) {
            return $cfg['imap'];
        }

        if (isset($cfg['host'], $cfg['username'], $cfg['password'])) {
            return [
                'host' => $cfg['host'],
                'port' => $cfg['port'] ?? null,
                'encryption' => $cfg['encryption'] ?? null,
                'username' => $cfg['username'],
                'password' => $cfg['password'],
                'validate_cert' => $cfg['validate_cert'] ?? null,
            ];
        }

        return null;
    }
}
