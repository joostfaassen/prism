<?php

namespace App\Integrations\Email;

use Psr\Cache\CacheItemPoolInterface;

class MessageCache
{
    public function __construct(
        private readonly CacheItemPoolInterface $emailCache,
    ) {
    }

    public function getFolderSignature(string $profileId, string $folder): ?FolderSignature
    {
        $item = $this->emailCache->getItem($this->folderSignatureKey($profileId, $folder));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();
        if (!is_array($value)) {
            return null;
        }

        return FolderSignature::fromArray($value);
    }

    public function setFolderSignature(string $profileId, string $folder, FolderSignature $signature): void
    {
        $item = $this->emailCache->getItem($this->folderSignatureKey($profileId, $folder));
        $item->set($signature->toArray());
        $this->emailCache->save($item);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessageBody(
        string $profileId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $includeHtml,
        int $maxBodyChars,
    ): ?array
    {
        $item = $this->emailCache->getItem(
            $this->messageBodyKey($profileId, $folder, $uidValidity, $uid, $includeHtml, $maxBodyChars),
        );
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function setMessageBody(
        string $profileId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $includeHtml,
        int $maxBodyChars,
        array $message,
    ): void {
        $item = $this->emailCache->getItem(
            $this->messageBodyKey($profileId, $folder, $uidValidity, $uid, $includeHtml, $maxBodyChars),
        );
        $item->set($message);
        $item->expiresAfter(60 * 60 * 24 * 30);
        $this->emailCache->save($item);
    }

    public function getMessagePointer(string $profileId, string $folder, int $uidValidity, int $uid): ?string
    {
        $item = $this->emailCache->getItem($this->messagePointerKey($profileId, $folder, $uidValidity, $uid));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setMessagePointer(string $profileId, string $folder, int $uidValidity, int $uid, string $messageId): void
    {
        if ($messageId === '') {
            return;
        }

        $item = $this->emailCache->getItem($this->messagePointerKey($profileId, $folder, $uidValidity, $uid));
        $item->set($messageId);
        $item->expiresAfter(60 * 60 * 24 * 30);
        $this->emailCache->save($item);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessageContentByMessageId(
        string $profileId,
        string $messageId,
        bool $includeHtml,
        int $maxBodyChars,
    ): ?array {
        if ($messageId === '') {
            return null;
        }

        $item = $this->emailCache->getItem(
            $this->messageContentKey($profileId, $messageId, $includeHtml, $maxBodyChars),
        );
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $message
     */
    public function setMessageContentByMessageId(
        string $profileId,
        string $messageId,
        bool $includeHtml,
        int $maxBodyChars,
        array $message,
    ): void {
        if ($messageId === '') {
            return;
        }

        $item = $this->emailCache->getItem(
            $this->messageContentKey($profileId, $messageId, $includeHtml, $maxBodyChars),
        );
        $item->set($message);
        $item->expiresAfter(60 * 60 * 24 * 30);
        $this->emailCache->save($item);
    }

    /**
     * @return array{seen: bool, flagged: bool, answered: bool, deleted: bool}|null
     */
    public function getMessageFlags(string $profileId, string $folder, int $uidValidity, int $uid): ?array
    {
        $item = $this->emailCache->getItem($this->messageFlagsKey($profileId, $folder, $uidValidity, $uid));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();
        if (!is_array($value)) {
            return null;
        }

        return [
            'seen' => (bool) ($value['seen'] ?? false),
            'flagged' => (bool) ($value['flagged'] ?? false),
            'answered' => (bool) ($value['answered'] ?? false),
            'deleted' => (bool) ($value['deleted'] ?? false),
        ];
    }

    public function setMessageFlags(
        string $profileId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $seen,
        bool $flagged,
        bool $answered,
        bool $deleted = false,
    ): void {
        $item = $this->emailCache->getItem($this->messageFlagsKey($profileId, $folder, $uidValidity, $uid));
        $item->set([
            'seen' => $seen,
            'flagged' => $flagged,
            'answered' => $answered,
            'deleted' => $deleted,
        ]);
        $item->expiresAfter(60 * 60 * 24 * 2);
        $this->emailCache->save($item);
    }

    public function deleteMessageFlags(string $profileId, string $folder, int $uidValidity, int $uid): void
    {
        $this->emailCache->deleteItem($this->messageFlagsKey($profileId, $folder, $uidValidity, $uid));
    }

    private function folderSignatureKey(string $profileId, string $folder): string
    {
        return sprintf('email.sig.%s.%s', $profileId, $this->folderHash($folder));
    }

    private function messageBodyKey(
        string $profileId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $includeHtml,
        int $maxBodyChars,
    ): string
    {
        return sprintf(
            'email.msg.%s.%s.%d.%d.%d.%d',
            $profileId,
            $this->folderHash($folder),
            $uidValidity,
            $uid,
            $includeHtml ? 1 : 0,
            $maxBodyChars,
        );
    }

    private function messagePointerKey(string $profileId, string $folder, int $uidValidity, int $uid): string
    {
        return sprintf('email.ptr.%s.%s.%d.%d', $profileId, $this->folderHash($folder), $uidValidity, $uid);
    }

    private function messageContentKey(string $profileId, string $messageId, bool $includeHtml, int $maxBodyChars): string
    {
        return sprintf(
            'email.content.%s.%s.%d.%d',
            $profileId,
            substr(sha1($messageId), 0, 32),
            $includeHtml ? 1 : 0,
            $maxBodyChars,
        );
    }

    private function messageFlagsKey(string $profileId, string $folder, int $uidValidity, int $uid): string
    {
        return sprintf('email.flags.%s.%s.%d.%d', $profileId, $this->folderHash($folder), $uidValidity, $uid);
    }

    private function folderHash(string $folder): string
    {
        return substr(sha1($folder), 0, 16);
    }
}
