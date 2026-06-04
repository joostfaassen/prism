<?php

namespace App\Email;

/**
 * A unified email account: IMAP for reading + writing to folders (e.g. Sent),
 * SMTP for outbound delivery, and the identity used as the "From" address.
 *
 * SMTP and identity are optional. An account that only has IMAP configured can
 * still be used for read-only tools, but `email_send` will refuse to use it.
 */
class EmailAccountConfig
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ImapConfig $imap,
        public readonly ?SmtpConfig $smtp,
        public readonly ?EmailIdentity $identity,
        public readonly string $sentFolder,
        public readonly string $draftsFolder,
    ) {
    }

    public function hasSmtp(): bool
    {
        return $this->smtp !== null;
    }

    /**
     * The address used as From when sending. Falls back to the IMAP username
     * (which is almost always also the email address for personal mailboxes).
     */
    public function getFromAddress(): string
    {
        if ($this->identity !== null && $this->identity->email !== '') {
            return $this->identity->email;
        }

        return $this->imap->username;
    }

    public function getFromName(): ?string
    {
        return $this->identity?->name;
    }
}
