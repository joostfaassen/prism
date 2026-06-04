<?php

namespace App\Email;

class FolderSignature
{
    public function __construct(
        public readonly int $uidValidity,
        public readonly int $uidNext,
        public readonly int $messages,
    ) {
    }

    /**
     * @param object{uidvalidity?: int, uidnext?: int, messages?: int}|false $status
     */
    public static function fromImapStatus(object|false $status): self
    {
        if ($status === false) {
            throw new \RuntimeException('Could not load IMAP folder status');
        }

        return new self(
            uidValidity: (int) ($status->uidvalidity ?? 0),
            uidNext: (int) ($status->uidnext ?? 0),
            messages: (int) ($status->messages ?? 0),
        );
    }

    /**
     * @return array{uid_validity: int, uid_next: int, messages: int}
     */
    public function toArray(): array
    {
        return [
            'uid_validity' => $this->uidValidity,
            'uid_next' => $this->uidNext,
            'messages' => $this->messages,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uidValidity: (int) ($data['uid_validity'] ?? 0),
            uidNext: (int) ($data['uid_next'] ?? 0),
            messages: (int) ($data['messages'] ?? 0),
        );
    }

    public function equals(self $other): bool
    {
        return $this->uidValidity === $other->uidValidity
            && $this->uidNext === $other->uidNext
            && $this->messages === $other->messages;
    }
}
