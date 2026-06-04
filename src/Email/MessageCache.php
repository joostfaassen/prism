<?php

namespace App\Email;

use Psr\Cache\CacheItemPoolInterface;

class MessageCache
{
    public function __construct(
        private readonly CacheItemPoolInterface $emailCache,
    ) {
    }

    public function getFolderSignature(string $accountId, string $folder): ?FolderSignature
    {
        $item = $this->emailCache->getItem($this->folderSignatureKey($accountId, $folder));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();
        if (!is_array($value)) {
            return null;
        }

        return FolderSignature::fromArray($value);
    }

    public function setFolderSignature(string $accountId, string $folder, FolderSignature $signature): void
    {
        $item = $this->emailCache->getItem($this->folderSignatureKey($accountId, $folder));
        $item->set($signature->toArray());
        $this->emailCache->save($item);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessageBody(
        string $accountId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $includeHtml,
        int $maxBodyChars,
    ): ?array
    {
        $item = $this->emailCache->getItem(
            $this->messageBodyKey($accountId, $folder, $uidValidity, $uid, $includeHtml, $maxBodyChars),
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
        string $accountId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $includeHtml,
        int $maxBodyChars,
        array $message,
    ): void {
        $item = $this->emailCache->getItem(
            $this->messageBodyKey($accountId, $folder, $uidValidity, $uid, $includeHtml, $maxBodyChars),
        );
        $item->set($message);
        $item->expiresAfter(60 * 60 * 24 * 30);
        $this->emailCache->save($item);
    }

    /**
     * @return array{seen: bool, flagged: bool, answered: bool}|null
     */
    public function getMessageFlags(string $accountId, string $folder, int $uidValidity, int $uid): ?array
    {
        $item = $this->emailCache->getItem($this->messageFlagsKey($accountId, $folder, $uidValidity, $uid));
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
        ];
    }

    public function setMessageFlags(
        string $accountId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $seen,
        bool $flagged,
        bool $answered,
    ): void {
        $item = $this->emailCache->getItem($this->messageFlagsKey($accountId, $folder, $uidValidity, $uid));
        $item->set([
            'seen' => $seen,
            'flagged' => $flagged,
            'answered' => $answered,
        ]);
        $item->expiresAfter(60 * 60 * 24 * 2);
        $this->emailCache->save($item);
    }

    public function deleteMessageFlags(string $accountId, string $folder, int $uidValidity, int $uid): void
    {
        $this->emailCache->deleteItem($this->messageFlagsKey($accountId, $folder, $uidValidity, $uid));
    }

    private function folderSignatureKey(string $accountId, string $folder): string
    {
        return sprintf('email.sig.%s.%s', $accountId, $this->folderHash($folder));
    }

    private function messageBodyKey(
        string $accountId,
        string $folder,
        int $uidValidity,
        int $uid,
        bool $includeHtml,
        int $maxBodyChars,
    ): string
    {
        return sprintf(
            'email.msg.%s.%s.%d.%d.%d.%d',
            $accountId,
            $this->folderHash($folder),
            $uidValidity,
            $uid,
            $includeHtml ? 1 : 0,
            $maxBodyChars,
        );
    }

    private function messageFlagsKey(string $accountId, string $folder, int $uidValidity, int $uid): string
    {
        return sprintf('email.flags.%s.%s.%d.%d', $accountId, $this->folderHash($folder), $uidValidity, $uid);
    }

    private function folderHash(string $folder): string
    {
        return substr(sha1($folder), 0, 16);
    }
}
