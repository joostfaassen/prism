<?php

namespace App\Email;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a Symfony Mime Email via the per-account SMTP transport. We build a
 * fresh transport per send because each account has its own credentials and
 * there's no benefit to keeping connections warm in this multi-tenant bridge.
 */
class SmtpMailer
{
    public function send(EmailAccountConfig $account, Email $email): SentMessage
    {
        if ($account->smtp === null) {
            throw new \RuntimeException(sprintf(
                'Email account "%s" has no SMTP configured — cannot send mail.',
                $account->id,
            ));
        }

        $transport = Transport::fromDsn($account->smtp->buildDsn());

        $envelope = new Envelope(
            new Address($account->getFromAddress()),
            $this->collectRecipientAddresses($email),
        );

        $sent = $transport->send($email, $envelope);

        if ($sent === null) {
            throw new \RuntimeException(sprintf(
                'SMTP transport returned no SentMessage for account "%s"',
                $account->id,
            ));
        }

        return $sent;
    }

    /**
     * @return list<Address>
     */
    private function collectRecipientAddresses(Email $email): array
    {
        $recipients = [];

        foreach (array_merge($email->getTo(), $email->getCc(), $email->getBcc()) as $addr) {
            if ($addr instanceof Address) {
                $recipients[] = $addr;
            }
        }

        if ($recipients === []) {
            throw new \InvalidArgumentException('Cannot send email with no recipients (To, Cc, Bcc all empty).');
        }

        return $recipients;
    }
}
