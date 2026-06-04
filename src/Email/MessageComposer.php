<?php

namespace App\Email;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Turns a high-level "send this" request (recipients, markdown body, optional
 * reply context) into a fully-formed Symfony Mime Email — multipart/alternative
 * with text + HTML, properly threaded, with original message quoted on replies.
 */
class MessageComposer
{
    public function __construct(
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    /**
     * @param list<string>           $to
     * @param list<string>           $cc
     * @param list<string>           $bcc
     */
    public function compose(
        EmailAccountConfig $account,
        array $to,
        array $cc,
        array $bcc,
        ?string $subject,
        string $bodyMarkdown,
        ?string $fromNameOverride,
        ?string $replyToOverride,
        ?ReplyContext $reply,
    ): ComposedMessage {
        $finalSubject = $this->resolveSubject($subject, $reply);

        $textBody = $this->markdown->toText($bodyMarkdown);
        $htmlBody = $this->markdown->toHtml($bodyMarkdown);

        if ($reply !== null) {
            $textBody = $this->appendQuotedText($textBody, $reply);
            $htmlBody = $this->appendQuotedHtml($htmlBody, $reply);
        }

        $email = new Email();

        $fromAddress = new Address(
            $account->getFromAddress(),
            $fromNameOverride ?? $account->getFromName() ?? '',
        );
        $email->from($fromAddress);

        $sender = $account->identity?->replyTo ?? $replyToOverride;
        if ($sender !== null && $sender !== '') {
            $email->replyTo(new Address($sender));
        } elseif ($account->identity?->replyTo !== null && $account->identity->replyTo !== '') {
            $email->replyTo(new Address($account->identity->replyTo));
        }

        if ($to !== []) {
            $email->to(...array_map(static fn(string $addr) => new Address($addr), $to));
        }
        if ($cc !== []) {
            $email->cc(...array_map(static fn(string $addr) => new Address($addr), $cc));
        }
        if ($bcc !== []) {
            $email->bcc(...array_map(static fn(string $addr) => new Address($addr), $bcc));
        }

        $email->subject($finalSubject);
        $email->text($textBody);
        $email->html($htmlBody);

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-Mailer', 'Prism MCP Bridge');

        $headers->addIdHeader('Message-ID', $this->generateMessageId($account));
        $headers->addDateHeader('Date', new \DateTimeImmutable('now'));

        if ($reply !== null && $reply->messageId !== null) {
            $headers->remove('In-Reply-To');
            $headers->remove('References');
            $headers->add(new UnstructuredHeader('In-Reply-To', '<' . $reply->messageId . '>'));

            $references = $reply->buildReferencesChain();
            if ($references !== []) {
                $referencesHeader = implode(' ', array_map(
                    static fn(string $id) => '<' . $id . '>',
                    $references,
                ));
                $headers->add(new UnstructuredHeader('References', $referencesHeader));
            }
        }

        return new ComposedMessage(
            email: $email,
            subject: $finalSubject,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            inReplyTo: $reply?->messageId,
        );
    }

    /**
     * Compute the recipients of a reply when `reply_all` is requested.
     * Mirrors what Thunderbird et al. do: To = original Reply-To/From,
     * Cc = original To + Cc minus our own address (and any already-included
     * recipients).
     *
     * @param list<array{name: string|null, address: string}> $extraReplyTo Original message Reply-To, if any
     * @return array{to: list<string>, cc: list<string>}
     */
    public function buildReplyAllRecipients(
        EmailAccountConfig $account,
        ReplyContext $reply,
        array $extraReplyTo,
    ): array {
        $self = strtolower($account->getFromAddress());

        $primary = $extraReplyTo !== [] ? $extraReplyTo : [$reply->originalFrom];
        $to = [];
        foreach ($primary as $addr) {
            $email = strtolower($addr['address'] ?? '');
            if ($email !== '' && $email !== $self && !in_array($email, $to, true)) {
                $to[] = $addr['address'];
            }
        }

        $seen = array_map(static fn(string $a) => strtolower($a), $to);
        $seen[] = $self;

        $cc = [];
        foreach (array_merge($reply->originalTo, $reply->originalCc) as $addr) {
            $email = strtolower($addr['address'] ?? '');
            if ($email === '' || in_array($email, $seen, true)) {
                continue;
            }
            $cc[] = $addr['address'];
            $seen[] = $email;
        }

        return ['to' => $to, 'cc' => $cc];
    }

    /**
     * Build a stable Message-ID with the local part = random hex and the
     * domain = the From address's domain (preferred by spam filters that
     * verify Message-ID alignment).
     */
    private function generateMessageId(EmailAccountConfig $account): string
    {
        $from = $account->getFromAddress();
        $domain = 'prism.local';

        $atPos = strrpos($from, '@');
        if ($atPos !== false) {
            $candidate = substr($from, $atPos + 1);
            if ($candidate !== '') {
                $domain = $candidate;
            }
        }

        $local = bin2hex(random_bytes(16));

        return $local . '@' . $domain;
    }

    private function resolveSubject(?string $subject, ?ReplyContext $reply): string
    {
        if ($subject !== null && trim($subject) !== '') {
            return $subject;
        }

        if ($reply !== null) {
            return $reply->getReplySubject();
        }

        return '(no subject)';
    }

    private function appendQuotedText(string $body, ReplyContext $reply): string
    {
        $attribution = $this->buildAttribution($reply);

        if ($reply->originalBodyText === null || $reply->originalBodyText === '') {
            return $body . "\n\n" . $attribution . "\n";
        }

        $quoted = preg_replace('/^/m', '> ', rtrim($reply->originalBodyText)) ?? '';

        return rtrim($body) . "\n\n" . $attribution . "\n" . $quoted . "\n";
    }

    private function appendQuotedHtml(string $body, ReplyContext $reply): string
    {
        $attribution = htmlspecialchars($this->buildAttribution($reply), ENT_QUOTES, 'UTF-8');

        $quotedBlock = '<p>' . $attribution . '</p>';

        if ($reply->originalBodyHtml !== null && $reply->originalBodyHtml !== '') {
            $inner = $this->extractBodyContents($reply->originalBodyHtml);
            $quotedBlock .= '<blockquote type="cite">' . $inner . '</blockquote>';
        } elseif ($reply->originalBodyText !== null && $reply->originalBodyText !== '') {
            $escaped = htmlspecialchars($reply->originalBodyText, ENT_QUOTES, 'UTF-8');
            $quotedBlock .= '<blockquote type="cite"><pre style="white-space: pre-wrap; font-family: inherit;">' . $escaped . '</pre></blockquote>';
        }

        $insertion = "\n<hr />\n" . $quotedBlock . "\n";

        $closing = '</body>';
        $pos = stripos($body, $closing);
        if ($pos === false) {
            return $body . $insertion;
        }

        return substr($body, 0, $pos) . $insertion . substr($body, $pos);
    }

    private function buildAttribution(ReplyContext $reply): string
    {
        $sender = $this->formatAddressForAttribution($reply->originalFrom);
        $when = $reply->originalDate;

        if ($when !== null) {
            try {
                $when = (new \DateTimeImmutable($when))->format('D, j M Y \a\t H:i');
            } catch (\Exception) {
                // keep as-is
            }
        }

        if ($when !== null && $when !== '') {
            return sprintf('On %s, %s wrote:', $when, $sender);
        }

        return sprintf('%s wrote:', $sender);
    }

    /**
     * @param array{name: string|null, address: string} $addr
     */
    private function formatAddressForAttribution(array $addr): string
    {
        $name = $addr['name'] ?? null;
        $email = $addr['address'] ?? '';

        if ($name !== null && $name !== '' && $email !== '') {
            return sprintf('%s <%s>', $name, $email);
        }

        return $email !== '' ? $email : 'sender';
    }

    /**
     * Pull just the contents of <body> from the original HTML so we don't end
     * up with a nested <html><body> inside the new message body.
     */
    private function extractBodyContents(string $html): string
    {
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches)) {
            return $matches[1];
        }

        return $html;
    }
}
