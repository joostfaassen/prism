<?php

namespace App\Email;

use Symfony\Component\Mime\Email;

/**
 * The result of composing an outgoing message: the Symfony Mime Email object
 * (used for sending and for the saved Sent copy) plus a small summary used
 * for the MCP tool response and audit logs.
 */
class ComposedMessage
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     */
    public function __construct(
        public readonly Email $email,
        public readonly string $subject,
        public readonly array $to,
        public readonly array $cc,
        public readonly array $bcc,
        public readonly ?string $inReplyTo,
    ) {
    }
}
