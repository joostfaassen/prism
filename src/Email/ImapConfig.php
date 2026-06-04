<?php

namespace App\Email;

class ImapConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $username,
        public readonly string $password,
        public readonly bool $validateCert,
    ) {
    }

    public function getMailboxString(string $folder = 'INBOX'): string
    {
        return $this->getServerString() . $folder;
    }

    public function getServerString(): string
    {
        $flags = match ($this->encryption) {
            'ssl' => '/imap/ssl',
            'tls' => '/imap/tls',
            default => '/imap',
        };

        if (!$this->validateCert) {
            $flags .= '/novalidate-cert';
        }

        return sprintf('{%s:%d%s}', $this->host, $this->port, $flags);
    }
}
