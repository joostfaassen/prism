<?php

namespace App\Email;

/**
 * Captures everything we need from the message being replied to so the new
 * message can be threaded correctly and quote the original cleanly — exactly
 * how a regular email client behaves.
 */
class ReplyContext
{
    /**
     * @param list<string>                                              $references
     * @param list<array{name: string|null, address: string}>           $originalTo
     * @param list<array{name: string|null, address: string}>           $originalCc
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly array $references,
        public readonly string $originalSubject,
        public readonly array $originalFrom,
        public readonly array $originalTo,
        public readonly array $originalCc,
        public readonly ?string $originalDate,
        public readonly ?string $originalBodyText,
        public readonly ?string $originalBodyHtml,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function fromImapMessage(array $message): self
    {
        return new self(
            messageId: $message['message_id'] ?? null,
            references: array_values(array_filter(array_unique(array_merge(
                $message['references'] ?? [],
                $message['in_reply_to'] !== null ? [$message['in_reply_to']] : [],
            )))),
            originalSubject: (string) ($message['subject'] ?? ''),
            originalFrom: $message['from'] ?? ['name' => null, 'address' => ''],
            originalTo: $message['to'] ?? [],
            originalCc: $message['cc'] ?? [],
            originalDate: $message['date'] ?? null,
            originalBodyText: $message['body_text'] ?? null,
            originalBodyHtml: $message['body_html'] ?? null,
        );
    }

    /**
     * Build the References header chain for the reply: original references
     * (oldest → newest), then the original message id.
     *
     * @return list<string>
     */
    public function buildReferencesChain(): array
    {
        $chain = $this->references;

        if ($this->messageId !== null && !in_array($this->messageId, $chain, true)) {
            $chain[] = $this->messageId;
        }

        return array_values($chain);
    }

    public function getReplySubject(): string
    {
        $subject = trim($this->originalSubject);

        if ($subject === '') {
            return 'Re:';
        }

        if (preg_match('/^(re|aw|antw|sv)\s*(\[\d+\])?\s*:/i', $subject)) {
            return $subject;
        }

        return 'Re: ' . $subject;
    }
}
