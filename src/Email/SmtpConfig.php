<?php

namespace App\Email;

class SmtpConfig
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

    /**
     * Build a Symfony Mailer DSN.
     *
     * Encryption modes:
     *   - "ssl" / "tls"      → implicit TLS (smtps://)
     *   - "starttls" / "tls" → opportunistic STARTTLS (smtp://)
     *   - "none" / ""        → plain (smtp://)
     *
     * "tls" historically means "STARTTLS" in many mail clients but means
     * "implicit TLS" in Symfony Mailer's DSN. To avoid surprises we treat
     * "ssl" as smtps:// (port 465 typically) and everything else as smtp://
     * with auto-STARTTLS on port 587.
     */
    public function buildDsn(): string
    {
        $scheme = $this->encryption === 'ssl' ? 'smtps' : 'smtp';
        $userinfo = '';

        if ($this->username !== '') {
            $userinfo = rawurlencode($this->username) . ':' . rawurlencode($this->password) . '@';
        }

        $dsn = sprintf('%s://%s%s:%d', $scheme, $userinfo, $this->host, $this->port);

        $params = [];

        if (!$this->validateCert) {
            $params['verify_peer'] = '0';
        }

        if ($params !== []) {
            $dsn .= '?' . http_build_query($params);
        }

        return $dsn;
    }
}
