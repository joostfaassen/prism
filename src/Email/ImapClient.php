<?php

namespace App\Email;

class ImapClient
{
    public function __construct(
        private readonly MessageCache $messageCache,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listAccountSummary(EmailAccountConfig $account): array
    {
        return [
            'id' => $account->id,
            'label' => $account->label,
            'imap_host' => $account->imap->host,
            'username' => $account->imap->username,
            'can_send' => $account->hasSmtp(),
            'from' => $account->getFromAddress(),
            'from_name' => $account->getFromName(),
            'sent_folder' => $account->sentFolder,
            'drafts_folder' => $account->draftsFolder,
        ];
    }

    /**
     * @return list<array{name: string, delimiter: string, total: int, unseen: int}>
     */
    public function listFolders(EmailAccountConfig $account, string $pattern = '*'): array
    {
        $conn = $this->connect($account);

        try {
            $serverStr = $account->imap->getServerString();
            $folders = imap_list($conn, $serverStr, $pattern);
            if ($folders === false) {
                return [];
            }

            $result = [];
            foreach ($folders as $folderPath) {
                $folderName = str_replace($serverStr, '', $folderPath);
                $status = imap_status($conn, $folderPath, SA_MESSAGES | SA_UNSEEN);
                $result[] = [
                    'name' => $folderName,
                    'delimiter' => '.',
                    'total' => $status ? $status->messages : 0,
                    'unseen' => $status ? $status->unseen : 0,
                ];
            }

            return $result;
        } finally {
            imap_close($conn);
        }
    }

    /**
     * Create an IMAP folder. Errors if a folder with that name already exists.
     *
     * @return array{folder: string, created: true}
     */
    public function createFolder(EmailAccountConfig $account, string $folder): array
    {
        $folder = trim($folder);
        if ($folder === '') {
            throw new \InvalidArgumentException('Folder name must be a non-empty string');
        }

        $conn = $this->connect($account);
        $serverStr = $account->imap->getServerString();
        $folderPath = $serverStr . $folder;

        try {
            $existing = imap_list($conn, $serverStr, $folder);
            if ($existing !== false && $existing !== []) {
                throw new \RuntimeException(sprintf('Folder "%s" already exists', $folder));
            }

            if (!@imap_createmailbox($conn, $folderPath)) {
                $errors = imap_errors() ?: [];
                $message = implode('; ', $errors) ?: 'unknown IMAP error';
                if ($this->looksLikeAlreadyExistsError($message)) {
                    throw new \RuntimeException(sprintf('Folder "%s" already exists', $folder));
                }

                throw new \RuntimeException(sprintf(
                    'Failed to create folder "%s": %s',
                    $folder,
                    $message,
                ));
            }

            return [
                'folder' => $folder,
                'created' => true,
            ];
        } finally {
            imap_close($conn);
        }
    }

    private function looksLikeAlreadyExistsError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'alreadyexists')
            || str_contains($lower, 'already exists')
            || str_contains($lower, 'already exist')
            || str_contains($lower, 'mailbox already');
    }

    /**
     * @return array<string, mixed>
     */
    public function listLabels(
        EmailAccountConfig $account,
        string $folder = 'INBOX',
        bool $includeFolders = true,
        bool $includeKeywords = true,
        int $messageLimit = 1000,
    ): array {
        $result = [
            'folders' => $includeFolders ? $this->listFolders($account) : [],
            'standard_flags' => ['\\Seen', '\\Answered', '\\Flagged', '\\Deleted', '\\Draft'],
            'custom_keywords' => [],
            'permanent_keywords' => [],
            'scanned_folder' => $folder,
            'scanned_messages' => 0,
        ];

        if (!$includeKeywords) {
            return $result;
        }

        $scan = $this->withRawImap($account, $folder, function ($stream) use ($messageLimit): array {
            $searchLines = $this->imapCommand($stream, 'UID SEARCH ALL');
            $uids = $this->parseSearchUids($searchLines);
            rsort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, 0, max(0, $messageLimit));

            $keywords = [];
            foreach (array_chunk($uids, 100) as $chunk) {
                foreach ($this->fetchFlagsFromStream($stream, $chunk) as $flags) {
                    foreach ($this->customKeywordsFromFlags($flags) as $keyword) {
                        $keywords[$keyword] = true;
                    }
                }
            }

            $custom = array_keys($keywords);
            sort($custom);

            return [
                'custom_keywords' => $custom,
                'scanned_messages' => count($uids),
            ];
        });

        $result['custom_keywords'] = $scan['result']['custom_keywords'];
        $result['permanent_keywords'] = $scan['permanent_keywords'];
        $result['scanned_messages'] = $scan['result']['scanned_messages'];

        return $result;
    }

    /**
     * Read standard IMAP flags and custom keyword tags/labels for one message.
     *
     * @return array<string, mixed>
     */
    public function getMessageLabels(
        EmailAccountConfig $account,
        string $folder,
        int $uid,
    ): array {
        $flagSets = $this->fetchMessageFlagSets($account, $folder, [$uid]);
        if (!isset($flagSets[$uid])) {
            throw new \RuntimeException(sprintf('Message UID %d not found in folder "%s"', $uid, $folder));
        }

        return $this->normalizeMessageLabels($folder, $uid, $flagSets[$uid]);
    }

    /**
     * @return array{total: int, offset: int, messages: list<array<string, mixed>>}
     */
    public function search(
        EmailAccountConfig $account,
        string $folder,
        ?string $from,
        ?string $to,
        ?string $subject,
        ?string $body,
        ?string $since,
        ?string $before,
        bool $unseenOnly,
        bool $flaggedOnly,
        int $limit,
        int $offset,
        bool $includeDeleted = false,
    ): array {
        $conn = $this->connect($account, $folder);

        try {
            $criteria = $this->buildSearchCriteria(
                $from,
                $to,
                $subject,
                $body,
                $since,
                $before,
                $unseenOnly,
                $flaggedOnly,
                $includeDeleted,
            );

            $uids = imap_search($conn, $criteria, SE_UID);
            if ($uids === false) {
                return ['total' => 0, 'offset' => $offset, 'messages' => []];
            }

            rsort($uids, SORT_NUMERIC);
            $total = count($uids);
            $slice = array_slice($uids, $offset, min($limit, 100));

            return [
                'total' => $total,
                'offset' => $offset,
                'messages' => $this->fetchMessageSummaries($conn, array_map(static fn ($uid): int => (int) $uid, $slice)),
            ];
        } finally {
            imap_close($conn);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessage(
        EmailAccountConfig $account,
        string $folder,
        int $uid,
        bool $includeHtml = false,
        int $maxBodyChars = 8000,
    ): array {
        $messages = $this->getMessages($account, $folder, [$uid], $includeHtml, $maxBodyChars);
        if ($messages === []) {
            throw new \RuntimeException(sprintf('Message UID %d not found', $uid));
        }

        return $messages[0];
    }

    /**
     * @param list<int> $uids
     * @param array<int|string, string> $knownMessageIds
     * @return list<array<string, mixed>>
     */
    public function getMessages(
        EmailAccountConfig $account,
        string $folder,
        array $uids,
        bool $includeHtml = false,
        int $maxBodyChars = 8000,
        ?callable $onProgress = null,
        array $knownMessageIds = [],
    ): array {
        $uids = $this->normalizeUids($uids);
        if ($uids === []) {
            return [];
        }

        $conn = $this->connect($account, $folder);

        try {
            $signature = $this->refreshFolderSignature($account, $folder, $conn);
            $resultByUid = [];
            $missUids = [];
            $knownMessageIds = $this->normalizeKnownMessageIds($knownMessageIds);

            foreach ($uids as $uid) {
                $messageId = $knownMessageIds[$uid]
                    ?? $this->messageCache->getMessagePointer($account->id, $folder, $signature->uidValidity, $uid);

                if ($messageId !== null) {
                    $this->messageCache->setMessagePointer($account->id, $folder, $signature->uidValidity, $uid, $messageId);
                    $cached = $this->messageCache->getMessageContentByMessageId(
                        $account->id,
                        $messageId,
                        $includeHtml,
                        $maxBodyChars,
                    );
                } else {
                    $cached = null;
                }

                if ($cached === null) {
                    $cached = $this->messageCache->getMessageBody(
                        $account->id,
                        $folder,
                        $signature->uidValidity,
                        $uid,
                        $includeHtml,
                        $maxBodyChars,
                    );
                    $legacyMessageId = $this->messageIdFromMessage($cached);
                    if ($legacyMessageId !== null) {
                        $this->messageCache->setMessagePointer($account->id, $folder, $signature->uidValidity, $uid, $legacyMessageId);
                        $this->messageCache->setMessageContentByMessageId(
                            $account->id,
                            $legacyMessageId,
                            $includeHtml,
                            $maxBodyChars,
                            $cached,
                        );
                    }
                }

                if ($cached === null) {
                    $missUids[] = $uid;
                    continue;
                }

                $resultByUid[$uid] = $this->withCurrentFolderState(
                    $cached,
                    $account->id,
                    $folder,
                    $signature->uidValidity,
                    $uid,
                );
            }

            if ($missUids !== []) {
                foreach ($this->fetchMessageSummaries($conn, $missUids) as $summary) {
                    $uid = (int) ($summary['uid'] ?? 0);
                    $messageId = $this->messageIdFromMessage($summary);
                    if ($uid <= 0 || $messageId === null) {
                        continue;
                    }

                    $knownMessageIds[$uid] = $messageId;
                    $this->messageCache->setMessagePointer($account->id, $folder, $signature->uidValidity, $uid, $messageId);

                    $cached = $this->messageCache->getMessageContentByMessageId(
                        $account->id,
                        $messageId,
                        $includeHtml,
                        $maxBodyChars,
                    );
                    if ($cached === null) {
                        continue;
                    }

                    $this->messageCache->setMessageFlags(
                        $account->id,
                        $folder,
                        $signature->uidValidity,
                        $uid,
                        (bool) ($summary['seen'] ?? false),
                        (bool) ($summary['flagged'] ?? false),
                        (bool) ($summary['answered'] ?? false),
                        (bool) ($summary['deleted'] ?? false),
                    );
                    $resultByUid[$uid] = $this->withCurrentFolderState(
                        $cached,
                        $account->id,
                        $folder,
                        $signature->uidValidity,
                        $uid,
                    );
                }

                $missUids = array_values(array_filter(
                    $missUids,
                    static fn (int $uid): bool => !isset($resultByUid[$uid]),
                ));
            }

            if ($onProgress !== null) {
                $onProgress([
                    'type' => 'cache_scan_done',
                    'total' => count($uids),
                    'cached' => count($uids) - count($missUids),
                    'missing' => count($missUids),
                ]);
            }

            $missCount = count($missUids);
            $missIndex = 0;
            foreach ($missUids as $uid) {
                $missIndex++;
                if ($onProgress !== null) {
                    $onProgress([
                        'type' => 'download_uid',
                        'uid' => $uid,
                        'index' => $missIndex,
                        'total' => $missCount,
                    ]);
                }

                $message = $this->readMessage($conn, $uid, $includeHtml, $maxBodyChars);
                $resultByUid[$uid] = $message;
                $messageId = (string) ($message['message_id'] ?? '');

                if ($messageId !== '') {
                    $this->messageCache->setMessagePointer($account->id, $folder, $signature->uidValidity, $uid, $messageId);
                    $this->messageCache->setMessageContentByMessageId(
                        $account->id,
                        $messageId,
                        $includeHtml,
                        $maxBodyChars,
                        $message,
                    );
                }

                $this->messageCache->setMessageBody(
                    $account->id,
                    $folder,
                    $signature->uidValidity,
                    $uid,
                    $includeHtml,
                    $maxBodyChars,
                    $message,
                );
                $this->messageCache->setMessageFlags(
                    $account->id,
                    $folder,
                    $signature->uidValidity,
                    $uid,
                    (bool) ($message['seen'] ?? false),
                    (bool) ($message['flagged'] ?? false),
                    (bool) ($message['answered'] ?? false),
                    (bool) ($message['deleted'] ?? false),
                );
            }

            if ($onProgress !== null) {
                $onProgress([
                    'type' => 'download_done',
                    'downloaded' => $missCount,
                ]);
            }

            $ordered = [];
            foreach ($uids as $uid) {
                if (isset($resultByUid[$uid])) {
                    $ordered[] = $resultByUid[$uid];
                }
            }

            return $ordered;
        } finally {
            imap_close($conn);
        }
    }

    /**
     * @param list<string> $folders
     * @return array{queries: list<array{folder: string, total: int, offset: int, messages: list<array<string,mixed>>}>}
     */
    public function multiSearch(
        EmailAccountConfig $account,
        array $folders,
        ?string $from,
        ?string $to,
        ?string $subject,
        ?string $body,
        ?string $since,
        ?string $before,
        bool $unseenOnly,
        bool $flaggedOnly,
        int $limitPerFolder,
        int $offset,
        bool $includeDeleted = false,
    ): array {
        $queries = [];
        $normalizedFolders = array_values(array_unique(array_filter(array_map('trim', $folders), static fn (string $folder): bool => $folder !== '')));
        if ($normalizedFolders === []) {
            $normalizedFolders = ['INBOX'];
        }

        foreach ($normalizedFolders as $folder) {
            $result = $this->search(
                $account,
                $folder,
                $from,
                $to,
                $subject,
                $body,
                $since,
                $before,
                $unseenOnly,
                $flaggedOnly,
                $limitPerFolder,
                $offset,
                $includeDeleted,
            );

            $queries[] = [
                'folder' => $folder,
                'total' => $result['total'],
                'offset' => $result['offset'],
                'messages' => $result['messages'],
            ];
        }

        return ['queries' => $queries];
    }

    /**
     * @return array{folder: string, warmed: int, inspected: int, cached: int}
     */
    public function warmRecentCache(
        EmailAccountConfig $account,
        string $folder,
        int $days = 7,
        int $limit = 200,
        ?callable $onProgress = null,
    ): array {
        $days = max(1, min($days, 31));
        $limit = max(1, min($limit, 500));
        $since = (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('c');

        $search = $this->search(
            $account,
            $folder,
            null,
            null,
            null,
            null,
            $since,
            null,
            false,
            false,
            $limit,
            0,
        );

        $uids = [];
        $knownMessageIds = [];
        foreach ($search['messages'] as $message) {
            $uid = (int) ($message['uid'] ?? 0);
            if ($uid > 0) {
                $uids[] = $uid;
                $messageId = $this->messageIdFromMessage($message);
                if ($messageId !== null) {
                    $knownMessageIds[$uid] = $messageId;
                }
            }
        }

        $cacheScan = ['cached' => 0, 'missing' => count($uids)];
        $downloaded = 0;
        $this->getMessages(
            $account,
            $folder,
            $uids,
            false,
            8000,
            static function (array $event) use (&$cacheScan, &$downloaded, $onProgress): void {
                if (($event['type'] ?? '') === 'cache_scan_done') {
                    $cacheScan['cached'] = (int) ($event['cached'] ?? 0);
                    $cacheScan['missing'] = (int) ($event['missing'] ?? 0);
                } elseif (($event['type'] ?? '') === 'download_done') {
                    $downloaded = (int) ($event['downloaded'] ?? 0);
                }

                if ($onProgress !== null) {
                    $onProgress($event);
                }
            },
            $knownMessageIds,
        );

        return [
            'folder' => $folder,
            'warmed' => $downloaded,
            'inspected' => count($uids),
            'cached' => $cacheScan['cached'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function moveMessage(EmailAccountConfig $account, string $fromFolder, int $uid, string $toFolder): array
    {
        $conn = $this->connect($account, $fromFolder);

        try {
            if (!@imap_mail_move($conn, (string) $uid, $toFolder, CP_UID)) {
                $errors = imap_errors() ?: [];
                throw new \RuntimeException(sprintf(
                    'Failed to move message UID %d from "%s" to "%s": %s',
                    $uid,
                    $fromFolder,
                    $toFolder,
                    implode('; ', $errors),
                ));
            }

            if (!@imap_expunge($conn)) {
                $errors = imap_errors() ?: [];
                throw new \RuntimeException(sprintf(
                    'Message UID %d was marked for move to "%s", but expunge failed: %s',
                    $uid,
                    $toFolder,
                    implode('; ', $errors),
                ));
            }

            return [
                'moved' => true,
                'uid' => $uid,
                'from_folder' => $fromFolder,
                'to_folder' => $toFolder,
            ];
        } finally {
            imap_close($conn);
        }
    }

    /**
     * @param array<string, bool|null> $standardFlags
     * @param list<string>             $addLabels
     * @param list<string>             $removeLabels
     *
     * @return array<string, mixed>
     */
    public function updateMessageFlags(
        EmailAccountConfig $account,
        string $folder,
        int $uid,
        array $standardFlags,
        array $addLabels,
        array $removeLabels,
    ): array {
        $setFlags = [];
        $unsetFlags = [];
        foreach ($standardFlags as $flag => $enabled) {
            if ($enabled === true) {
                $setFlags[] = $flag;
            } elseif ($enabled === false) {
                $unsetFlags[] = $flag;
            }
        }

        $setFlags = array_values(array_unique(array_merge($setFlags, $addLabels)));
        $unsetFlags = array_values(array_unique(array_merge($unsetFlags, $removeLabels)));

        $conn = $this->connect($account, $folder);

        try {
            if ($setFlags !== []) {
                $this->setFlags($conn, $uid, $setFlags);
            }
            if ($unsetFlags !== []) {
                $this->unsetFlags($conn, $uid, $unsetFlags);
            }

            $signature = $this->refreshFolderSignature($account, $folder, $conn);
            $this->messageCache->deleteMessageFlags($account->id, $folder, $signature->uidValidity, $uid);
            $summary = $this->fetchMessageSummaries($conn, [$uid])[0] ?? ['uid' => $uid];
        } finally {
            imap_close($conn);
        }

        $labels = $this->getMessageLabels($account, $folder, $uid);
        $this->messageCache->setMessageFlags(
            $account->id,
            $folder,
            $signature->uidValidity,
            $uid,
            (bool) ($labels['seen'] ?? false),
            (bool) ($labels['flagged'] ?? false),
            (bool) ($labels['answered'] ?? false),
            (bool) ($labels['deleted'] ?? false),
        );

        return [
            'updated' => true,
            'uid' => $uid,
            'folder' => $folder,
            'set_flags' => $setFlags,
            'unset_flags' => $unsetFlags,
            'labels' => $labels['labels'],
            'message' => array_merge($summary, [
                'seen' => $labels['seen'],
                'flagged' => $labels['flagged'],
                'answered' => $labels['answered'],
                'deleted' => $labels['deleted'],
                'draft' => $labels['draft'],
                'labels' => $labels['labels'],
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessageForReply(EmailAccountConfig $account, string $folder, int $uid): array
    {
        return $this->getMessage($account, $folder, $uid, includeHtml: true, maxBodyChars: 200000);
    }

    /**
     * Fetch the complete, unparsed RFC822/MIME source for a single message.
     *
     * Returns the raw bytes exactly as stored on the server (headers + body,
     * including base64/quoted-printable encoded attachment parts). Uses FT_PEEK
     * so the \Seen flag is not affected.
     */
    public function getRawMessage(EmailAccountConfig $account, string $folder, int $uid): string
    {
        $conn = $this->connect($account, $folder);

        try {
            $header = @imap_fetchheader($conn, $uid, FT_UID | FT_PREFETCHTEXT);
            $body = @imap_body($conn, $uid, FT_UID | FT_PEEK);

            if ($header === false || $body === false) {
                $errors = imap_errors() ?: [];
                throw new \RuntimeException(sprintf(
                    'Failed to fetch raw message UID %d from "%s": %s',
                    $uid,
                    $folder,
                    implode('; ', $errors) ?: 'message not found',
                ));
            }

            return $header . $body;
        } finally {
            imap_close($conn);
        }
    }

    /**
     * Append a raw RFC822 message to a folder. When $expectedMessageId is given,
     * attempt to resolve and return the new message's UID (via UIDNEXT window +
     * Message-ID match). Returns null when UID resolution is not requested or fails.
     */
    public function appendToFolder(
        EmailAccountConfig $account,
        string $folder,
        string $rawMessage,
        string $flags = '\\Seen',
        ?string $expectedMessageId = null,
    ): ?int {
        $conn = $this->connect($account);
        $serverStr = $account->imap->getServerString();
        $folderPath = $serverStr . $folder;

        try {
            $folders = imap_list($conn, $serverStr, $folder);
            if ($folders === false || $folders === []) {
                if (!@imap_createmailbox($conn, $folderPath)) {
                    $errors = imap_errors() ?: [];
                    throw new \RuntimeException(sprintf(
                        'Folder "%s" does not exist and could not be created: %s',
                        $folder,
                        implode('; ', $errors),
                    ));
                }
            }

            $uidFrom = null;
            if ($expectedMessageId !== null && $expectedMessageId !== '') {
                $status = @imap_status($conn, $folderPath, SA_UIDNEXT);
                if ($status !== false && isset($status->uidnext) && (int) $status->uidnext > 0) {
                    $uidFrom = (int) $status->uidnext;
                }
            }

            $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $rawMessage) ?? $rawMessage;
            if (!@imap_append($conn, $folderPath, $normalized, $flags)) {
                $errors = imap_errors() ?: [];
                throw new \RuntimeException(sprintf(
                    'Failed to append message to folder "%s": %s',
                    $folder,
                    implode('; ', $errors),
                ));
            }

            if ($uidFrom === null) {
                return null;
            }

            return $this->resolveAppendedUid($conn, $folderPath, $uidFrom, $expectedMessageId);
        } finally {
            imap_close($conn);
        }
    }

    /**
     * Permanently delete a message (mark \Deleted + expunge).
     *
     * @return array<string, mixed> overview summary of the deleted message
     */
    public function deleteMessage(
        EmailAccountConfig $account,
        string $folder,
        int $uid,
        bool $requireDraftFlag = true,
        ?string $expectedMessageId = null,
    ): array {
        if ($uid <= 0) {
            throw new \InvalidArgumentException('uid must be a positive integer');
        }

        $conn = $this->connect($account, $folder);

        try {
            $overview = imap_fetch_overview($conn, (string) $uid, FT_UID);
            if (!is_array($overview) || $overview === []) {
                throw new \RuntimeException(sprintf(
                    'Message UID %d not found in folder "%s"',
                    $uid,
                    $folder,
                ));
            }

            $item = $overview[0];
            $isDraft = (bool) ($item->draft ?? false);

            if ($requireDraftFlag && !$isDraft) {
                throw new \RuntimeException(sprintf(
                    'Message UID %d in "%s" does not have the \\Draft flag — refusing to delete (not a draft).',
                    $uid,
                    $folder,
                ));
            }

            $messageId = $this->normalizeMessageId($item->message_id ?? null);
            if ($expectedMessageId !== null && $expectedMessageId !== '') {
                $expected = $this->normalizeMessageId($expectedMessageId);
                if ($expected === null || $messageId === null || $messageId !== $expected) {
                    throw new \RuntimeException(sprintf(
                        'Message UID %d in "%s" has Message-ID "%s", expected "%s" — refusing to delete.',
                        $uid,
                        $folder,
                        $messageId ?? '(none)',
                        $expected ?? $expectedMessageId,
                    ));
                }
            }

            $subject = isset($item->subject) ? $this->decodeMime((string) $item->subject) : '';

            $signature = $this->refreshFolderSignature($account, $folder, $conn);

            if (!@imap_delete($conn, (string) $uid, FT_UID)) {
                $errors = imap_errors() ?: [];
                throw new \RuntimeException(sprintf(
                    'Failed to mark message UID %d for deletion in "%s": %s',
                    $uid,
                    $folder,
                    implode('; ', $errors),
                ));
            }

            if (!@imap_expunge($conn)) {
                $errors = imap_errors() ?: [];
                throw new \RuntimeException(sprintf(
                    'Message UID %d was marked deleted in "%s", but expunge failed: %s',
                    $uid,
                    $folder,
                    implode('; ', $errors),
                ));
            }

            $this->messageCache->deleteMessageFlags($account->id, $folder, $signature->uidValidity, $uid);

            return [
                'uid' => $uid,
                'folder' => $folder,
                'message_id' => $messageId,
                'subject' => $subject,
                'draft' => $isDraft,
            ];
        } finally {
            imap_close($conn);
        }
    }

    /**
     * After APPEND, find the new UID by scanning from the pre-append UIDNEXT
     * for a message whose Message-ID matches. Returns null on any failure —
     * the append already succeeded.
     */
    private function resolveAppendedUid(
        \IMAP\Connection $conn,
        string $folderPath,
        int $uidFrom,
        string $expectedMessageId,
    ): ?int {
        $expected = $this->normalizeMessageId($expectedMessageId);
        if ($expected === null) {
            return null;
        }

        if (!@imap_reopen($conn, $folderPath)) {
            return null;
        }

        $overview = @imap_fetch_overview($conn, $uidFrom . ':*', FT_UID);
        if (!is_array($overview) || $overview === []) {
            return null;
        }

        foreach ($overview as $item) {
            $messageId = $this->normalizeMessageId($item->message_id ?? null);
            if ($messageId !== null && $messageId === $expected) {
                $uid = (int) ($item->uid ?? 0);

                return $uid > 0 ? $uid : null;
            }
        }

        return null;
    }

    private function connect(EmailAccountConfig $account, string $folder = 'INBOX'): \IMAP\Connection
    {
        $mailbox = $account->imap->getMailboxString($folder);
        $conn = @imap_open($mailbox, $account->imap->username, $account->imap->password, 0, 3);
        if ($conn === false) {
            $errors = imap_errors() ?: [];
            throw new \RuntimeException(sprintf(
                'Failed to connect to email account "%s" (IMAP): %s',
                $account->id,
                implode('; ', $errors),
            ));
        }

        return $conn;
    }

    private function refreshFolderSignature(
        EmailAccountConfig $account,
        string $folder,
        \IMAP\Connection $conn,
    ): FolderSignature {
        $status = imap_status($conn, $account->imap->getServerString() . $folder, SA_UIDVALIDITY | SA_UIDNEXT | SA_MESSAGES);
        $signature = FolderSignature::fromImapStatus($status);
        $this->messageCache->setFolderSignature($account->id, $folder, $signature);

        return $signature;
    }

    /**
     * @param list<int> $uids
     * @return list<int>
     */
    private function normalizeUids(array $uids): array
    {
        $normalized = [];
        foreach ($uids as $uid) {
            if (is_int($uid) && $uid > 0) {
                $normalized[] = $uid;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int|string, string> $messageIds
     * @return array<int, string>
     */
    private function normalizeKnownMessageIds(array $messageIds): array
    {
        $normalized = [];
        foreach ($messageIds as $uid => $messageId) {
            $uid = (int) $uid;
            $messageId = $this->normalizeMessageId($messageId);
            if ($uid > 0 && $messageId !== null) {
                $normalized[$uid] = $messageId;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $message
     */
    private function messageIdFromMessage(?array $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $messageId = $message['message_id'] ?? null;

        return is_string($messageId) ? $this->normalizeMessageId($messageId) : null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function withCurrentFolderState(array $message, string $accountId, string $folder, int $uidValidity, int $uid): array
    {
        $message['uid'] = $uid;

        $flags = $this->messageCache->getMessageFlags($accountId, $folder, $uidValidity, $uid);
        if ($flags !== null) {
            $message['seen'] = $flags['seen'];
            $message['flagged'] = $flags['flagged'];
            $message['answered'] = $flags['answered'];
            $message['deleted'] = $flags['deleted'];
        }

        return $message;
    }

    /**
     * @param list<int> $uids
     * @return list<array<string, mixed>>
     */
    private function fetchMessageSummaries(\IMAP\Connection $conn, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $overview = imap_fetch_overview($conn, implode(',', $uids), FT_UID);
        if (!is_array($overview)) {
            return [];
        }

        $result = [];
        foreach ($overview as $item) {
            $uid = (int) ($item->uid ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $result[] = [
                'uid' => $uid,
                'date' => isset($item->date) ? (new \DateTimeImmutable($item->date))->format('c') : null,
                'from' => $this->parseAddressString((string) ($item->from ?? '')),
                'to' => $this->parseAddressListString((string) ($item->to ?? '')),
                'subject' => isset($item->subject) ? $this->decodeMime((string) $item->subject) : '',
                'message_id' => $this->normalizeMessageId($item->message_id ?? null),
                'seen' => (bool) ($item->seen ?? false),
                'flagged' => (bool) ($item->flagged ?? false),
                'answered' => (bool) ($item->answered ?? false),
                'deleted' => (bool) ($item->deleted ?? false),
                'has_attachments' => false,
                'size' => (int) ($item->size ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMessage(\IMAP\Connection $conn, int $uid, bool $includeHtml, int $maxBodyChars): array
    {
        $overview = imap_fetch_overview($conn, (string) $uid, FT_UID);
        if (empty($overview)) {
            throw new \RuntimeException(sprintf('Message UID %d not found', $uid));
        }

        $header = $overview[0];
        $headerText = imap_fetchheader($conn, $uid, FT_UID);
        $parsedHeader = imap_rfc822_parse_headers($headerText);
        $structure = imap_fetchstructure($conn, $uid, FT_UID);

        $bodyText = null;
        $bodyHtml = null;
        $attachments = [];
        $this->extractParts($conn, $uid, $structure, '', $bodyText, $bodyHtml, $attachments);

        if ($bodyText !== null && strlen($bodyText) > $maxBodyChars) {
            $bodyText = mb_substr($bodyText, 0, $maxBodyChars) . "\n[truncated]";
        }

        $returnHtml = $includeHtml && $bodyHtml !== null;
        if ($returnHtml && strlen($bodyHtml) > $maxBodyChars) {
            $bodyHtml = mb_substr($bodyHtml, 0, $maxBodyChars) . "\n[truncated]";
        }

        return [
            'uid' => $uid,
            'message_id' => $this->normalizeMessageId($header->message_id ?? null),
            'in_reply_to' => $this->normalizeMessageId($parsedHeader->in_reply_to ?? null),
            'references' => $this->extractReferencesHeader($headerText),
            'date' => isset($header->date) ? (new \DateTimeImmutable($header->date))->format('c') : null,
            'from' => $this->parseAddress($parsedHeader->from ?? []),
            'to' => $this->parseAddressList($parsedHeader->to ?? []),
            'cc' => $this->parseAddressList($parsedHeader->cc ?? []),
            'reply_to' => $this->parseAddressList($parsedHeader->reply_to ?? []),
            'subject' => isset($header->subject) ? $this->decodeMime((string) $header->subject) : '',
            'body_text' => $bodyText,
            'body_html' => $returnHtml ? $bodyHtml : null,
            'seen' => (bool) ($header->seen ?? false),
            'flagged' => (bool) ($header->flagged ?? false),
            'answered' => (bool) ($header->answered ?? false),
            'deleted' => (bool) ($header->deleted ?? false),
            'attachments' => $attachments,
        ];
    }

    /**
     * @param list<object> $addresses
     * @return array{name: string|null, address: string}
     */
    private function parseAddress(array $addresses): array
    {
        if ($addresses === []) {
            return ['name' => null, 'address' => ''];
        }

        $addr = $addresses[0];
        $mailbox = $addr->mailbox ?? '';
        $host = $addr->host ?? '';
        $personal = isset($addr->personal) ? $this->decodeMime((string) $addr->personal) : null;

        return ['name' => $personal, 'address' => $host !== '' ? "{$mailbox}@{$host}" : $mailbox];
    }

    /**
     * @param list<object> $addresses
     * @return list<array{name: string|null, address: string}>
     */
    private function parseAddressList(array $addresses): array
    {
        $result = [];
        foreach ($addresses as $addr) {
            $mailbox = $addr->mailbox ?? '';
            $host = $addr->host ?? '';
            $personal = isset($addr->personal) ? $this->decodeMime((string) $addr->personal) : null;
            $result[] = ['name' => $personal, 'address' => $host !== '' ? "{$mailbox}@{$host}" : $mailbox];
        }

        return $result;
    }

    /**
     * @return array{name: string|null, address: string}
     */
    private function parseAddressString(string $value): array
    {
        $parsed = imap_rfc822_parse_adrlist($value, '');
        if (!is_array($parsed) || $parsed === []) {
            return ['name' => null, 'address' => trim($value)];
        }

        $first = $parsed[0];
        $mailbox = (string) ($first->mailbox ?? '');
        $host = (string) ($first->host ?? '');
        $name = isset($first->personal) ? $this->decodeMime((string) $first->personal) : null;

        return ['name' => $name, 'address' => $host !== '' ? $mailbox . '@' . $host : $mailbox];
    }

    /**
     * @return list<array{name: string|null, address: string}>
     */
    private function parseAddressListString(string $value): array
    {
        $parsed = imap_rfc822_parse_adrlist($value, '');
        if (!is_array($parsed)) {
            return [];
        }

        $result = [];
        foreach ($parsed as $item) {
            $mailbox = (string) ($item->mailbox ?? '');
            $host = (string) ($item->host ?? '');
            $name = isset($item->personal) ? $this->decodeMime((string) $item->personal) : null;
            $result[] = ['name' => $name, 'address' => $host !== '' ? $mailbox . '@' . $host : $mailbox];
        }

        return $result;
    }

    private function decodeMime(string $text): string
    {
        $decoded = imap_mime_header_decode($text);
        $result = '';

        foreach ($decoded as $part) {
            $charset = strtoupper((string) ($part->charset ?? 'default'));
            $chunk = (string) ($part->text ?? '');
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && $charset !== '') {
                $converted = @iconv($charset, 'UTF-8//TRANSLIT', $chunk);
                if ($converted !== false) {
                    $chunk = $converted;
                }
            }
            $result .= $chunk;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function extractReferencesHeader(string $headerText): array
    {
        if (!preg_match('/^References:\s*(.+?)(?:\r?\n[^\s]|$)/sim', $headerText, $matches)) {
            return [];
        }

        $raw = preg_replace('/\s+/', ' ', trim($matches[1])) ?? '';
        if ($raw === '' || !preg_match_all('/<([^>]+)>/', $raw, $ids)) {
            return [];
        }

        return array_values(array_unique($ids[1]));
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        if ($messageId === null || $messageId === '') {
            return null;
        }

        $trimmed = trim($messageId);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed[0] === '<' && str_ends_with($trimmed, '>')) {
            $trimmed = substr($trimmed, 1, -1);
        }

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param list<array{filename: string, mime_type: string, size: int}> $attachments
     */
    private function extractParts(
        \IMAP\Connection $conn,
        int $uid,
        \stdClass $structure,
        string $partNumber,
        ?string &$bodyText,
        ?string &$bodyHtml,
        array &$attachments,
    ): void {
        if (!empty($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $subPartNumber = $partNumber !== '' ? ($partNumber . '.' . ($index + 1)) : (string) ($index + 1);
                $this->extractParts($conn, $uid, $part, $subPartNumber, $bodyText, $bodyHtml, $attachments);
            }

            return;
        }

        $disposition = $structure->ifdisposition ? strtolower((string) $structure->disposition) : '';
        if ($disposition === 'attachment') {
            $filename = 'unnamed';
            if ($structure->ifdparameters) {
                foreach ($structure->dparameters as $param) {
                    if (strtolower((string) $param->attribute) === 'filename') {
                        $filename = $this->decodeMime((string) $param->value);
                        break;
                    }
                }
            } elseif ($structure->ifparameters) {
                foreach ($structure->parameters as $param) {
                    if (strtolower((string) $param->attribute) === 'name') {
                        $filename = $this->decodeMime((string) $param->value);
                        break;
                    }
                }
            }

            $attachments[] = [
                'filename' => $filename,
                'mime_type' => $this->getMimeType($structure),
                'size' => (int) ($structure->bytes ?? 0),
            ];

            return;
        }

        $type = $structure->type ?? 0;
        $subtype = strtolower((string) ($structure->subtype ?? ''));
        if ($type === 0 && $subtype === 'plain' && $bodyText === null) {
            $bodyText = $this->fetchDecodedBody($conn, $uid, $partNumber !== '' ? $partNumber : '1', (int) ($structure->encoding ?? 0), $structure);
        } elseif ($type === 0 && $subtype === 'html' && $bodyHtml === null) {
            $bodyHtml = $this->fetchDecodedBody($conn, $uid, $partNumber !== '' ? $partNumber : '1', (int) ($structure->encoding ?? 0), $structure);
        }
    }

    private function fetchDecodedBody(
        \IMAP\Connection $conn,
        int $uid,
        string $partNumber,
        int $encoding,
        \stdClass $structure,
    ): string {
        $body = imap_fetchbody($conn, $uid, $partNumber, FT_UID | FT_PEEK);
        $decoded = match ($encoding) {
            3 => base64_decode($body) ?: '',
            4 => quoted_printable_decode($body),
            default => $body,
        };

        $charset = $this->extractCharset($structure);
        if ($charset !== '' && strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT', $decoded);
            if ($converted !== false) {
                $decoded = $converted;
            }
        }

        return $decoded;
    }

    private function extractCharset(\stdClass $structure): string
    {
        if (!empty($structure->ifparameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower((string) $param->attribute) === 'charset') {
                    return (string) $param->value;
                }
            }
        }

        return '';
    }

    private function getMimeType(\stdClass $structure): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];
        $type = $types[$structure->type ?? 0] ?? 'application';
        $subtype = strtolower((string) ($structure->subtype ?? 'octet-stream'));

        return "{$type}/{$subtype}";
    }

    private function buildSearchCriteria(
        ?string $from,
        ?string $to,
        ?string $subject,
        ?string $body,
        ?string $since,
        ?string $before,
        bool $unseenOnly,
        bool $flaggedOnly,
        bool $includeDeleted = false,
    ): string {
        $parts = [];
        if (!$includeDeleted) {
            // Match mail-client UI defaults: hide soft-deleted messages until EXPUNGE.
            $parts[] = 'UNDELETED';
        }
        if ($from !== null) {
            $parts[] = sprintf('FROM "%s"', $from);
        }
        if ($to !== null) {
            $parts[] = sprintf('TO "%s"', $to);
        }
        if ($subject !== null) {
            $parts[] = sprintf('SUBJECT "%s"', $subject);
        }
        if ($body !== null) {
            $parts[] = sprintf('BODY "%s"', $body);
        }
        if ($since !== null) {
            $parts[] = sprintf('SINCE "%s"', (new \DateTimeImmutable($since))->format('d-M-Y'));
        }
        if ($before !== null) {
            $parts[] = sprintf('BEFORE "%s"', (new \DateTimeImmutable($before))->format('d-M-Y'));
        }
        if ($unseenOnly) {
            $parts[] = 'UNSEEN';
        }
        if ($flaggedOnly) {
            $parts[] = 'FLAGGED';
        }

        return $parts === [] ? 'ALL' : implode(' ', $parts);
    }

    /**
     * @param list<string> $flags
     */
    private function setFlags(\IMAP\Connection $conn, int $uid, array $flags): void
    {
        $flagString = implode(' ', $flags);
        if (!@imap_setflag_full($conn, (string) $uid, $flagString, ST_UID)) {
            $errors = imap_errors() ?: [];
            throw new \RuntimeException(sprintf(
                'Failed to set flags on UID %d: %s',
                $uid,
                $errors !== [] ? implode('; ', $errors) : 'unknown IMAP error',
            ));
        }
    }

    /**
     * @param list<string> $flags
     */
    private function unsetFlags(\IMAP\Connection $conn, int $uid, array $flags): void
    {
        $flagString = implode(' ', $flags);
        if (!@imap_clearflag_full($conn, (string) $uid, $flagString, ST_UID)) {
            $errors = imap_errors() ?: [];
            throw new \RuntimeException(sprintf(
                'Failed to clear flags on UID %d: %s',
                $uid,
                $errors !== [] ? implode('; ', $errors) : 'unknown IMAP error',
            ));
        }
    }

    /**
     * @param list<int> $uids
     * @return array<int, list<string>>
     */
    private function fetchMessageFlagSets(EmailAccountConfig $account, string $folder, array $uids): array
    {
        $uids = $this->normalizeUids($uids);
        if ($uids === []) {
            return [];
        }

        return $this->withRawImap($account, $folder, function ($stream) use ($uids): array {
            $result = [];
            foreach (array_chunk($uids, 100) as $chunk) {
                foreach ($this->fetchFlagsFromStream($stream, $chunk) as $uid => $flags) {
                    $result[$uid] = $flags;
                }
            }

            return $result;
        })['result'];
    }

    /**
     * @param list<string> $flags
     * @return array<string, mixed>
     */
    private function normalizeMessageLabels(string $folder, int $uid, array $flags): array
    {
        $normalized = [];
        foreach ($flags as $flag) {
            $flag = trim($flag);
            if ($flag === '' || $flag === '\\*' || $flag === '*') {
                continue;
            }
            $normalized[] = $flag;
        }
        $normalized = array_values(array_unique($normalized));

        $has = static function (string $name) use ($normalized): bool {
            foreach ($normalized as $flag) {
                if (strcasecmp($flag, $name) === 0) {
                    return true;
                }
            }

            return false;
        };

        return [
            'uid' => $uid,
            'folder' => $folder,
            'seen' => $has('\\Seen'),
            'flagged' => $has('\\Flagged'),
            'answered' => $has('\\Answered'),
            'deleted' => $has('\\Deleted'),
            'draft' => $has('\\Draft'),
            'labels' => $this->customKeywordsFromFlags($normalized),
            'flags' => $normalized,
        ];
    }

    /**
     * @param list<string> $flags
     * @return list<string>
     */
    private function customKeywordsFromFlags(array $flags): array
    {
        $standard = [
            '\\seen' => true,
            '\\answered' => true,
            '\\flagged' => true,
            '\\deleted' => true,
            '\\draft' => true,
            '\\recent' => true,
            '\\*' => true,
            '*' => true,
        ];

        $keywords = [];
        foreach ($flags as $flag) {
            $flag = trim($flag);
            if ($flag === '' || isset($standard[strtolower($flag)])) {
                continue;
            }
            $keywords[] = $flag;
        }

        sort($keywords);

        return array_values(array_unique($keywords));
    }

    /**
     * @template T
     * @param callable(resource): T $callback
     * @return array{result: T, permanent_keywords: list<string>}
     */
    private function withRawImap(EmailAccountConfig $account, string $folder, callable $callback): array
    {
        $stream = $this->openRawImapStream($account);

        try {
            $this->imapCommand(
                $stream,
                'LOGIN ' . $this->quoteImapString($account->imap->username) . ' ' . $this->quoteImapString($account->imap->password),
            );

            $selectLines = $this->imapCommand($stream, 'SELECT ' . $this->quoteImapString($folder));
            $permanentKeywords = $this->parsePermanentKeywords($selectLines);

            $result = $callback($stream);

            return [
                'result' => $result,
                'permanent_keywords' => $permanentKeywords,
            ];
        } finally {
            try {
                $this->imapCommand($stream, 'LOGOUT');
            } catch (\Throwable) {
                // Connection may already be closing.
            }
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function openRawImapStream(EmailAccountConfig $account)
    {
        $host = $account->imap->host;
        $port = $account->imap->port;
        $validateCert = $account->imap->validateCert;
        $encryption = $account->imap->encryption;

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $validateCert,
                'verify_peer_name' => $validateCert,
                'allow_self_signed' => !$validateCert,
            ],
        ]);

        $stream = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            throw new \RuntimeException(sprintf(
                'Failed to open IMAP socket for account "%s": %s (%d)',
                $account->id,
                $errstr !== '' ? $errstr : 'unknown error',
                $errno,
            ));
        }

        stream_set_timeout($stream, 30);
        $this->imapReadGreeting($stream);

        if ($encryption === 'tls') {
            $this->imapCommand($stream, 'STARTTLS');
            $crypto = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                fclose($stream);
                throw new \RuntimeException(sprintf(
                    'Failed to negotiate STARTTLS for email account "%s"',
                    $account->id,
                ));
            }
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function imapReadGreeting($stream): void
    {
        $line = $this->imapReadLine($stream);
        if ($line === null || !str_starts_with($line, '* OK')) {
            throw new \RuntimeException('Invalid IMAP server greeting: ' . ($line ?? '(empty)'));
        }
    }

    /**
     * @param resource $stream
     * @return list<string>
     */
    private function imapCommand($stream, string $command): array
    {
        static $counter = 0;
        $tag = 'A' . (++$counter);
        if (@fwrite($stream, $tag . ' ' . $command . "\r\n") === false) {
            throw new \RuntimeException('Failed to write IMAP command');
        }

        $untagged = [];
        while (true) {
            $line = $this->imapReadLine($stream);
            if ($line === null) {
                throw new \RuntimeException('IMAP connection closed unexpectedly');
            }

            if (str_starts_with($line, '* ')) {
                $untagged[] = $line;
                continue;
            }

            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . preg_quote($tag, '/') . ' OK\b/i', $line)) {
                    throw new \RuntimeException('IMAP command failed: ' . $line);
                }

                return $untagged;
            }
        }
    }

    /**
     * @param resource $stream
     */
    private function imapReadLine($stream): ?string
    {
        $line = @fgets($stream);
        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    private function quoteImapString(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @param resource $stream
     * @param list<int> $uids
     * @return array<int, list<string>>
     */
    private function fetchFlagsFromStream($stream, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $lines = $this->imapCommand($stream, 'UID FETCH ' . implode(',', $uids) . ' (FLAGS)');
        $result = [];
        foreach ($lines as $line) {
            if (!preg_match('/\bUID\s+(\d+)\b/i', $line, $uidMatch)) {
                continue;
            }
            if (!preg_match('/\bFLAGS\s*\(([^)]*)\)/i', $line, $flagsMatch)) {
                continue;
            }

            $uid = (int) $uidMatch[1];
            $result[$uid] = $this->parseFlagList($flagsMatch[1]);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function parseFlagList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        preg_match_all('/\\\\[^\s]+|[^\s]+/', $raw, $matches);

        return array_values(array_filter(
            $matches[0] ?? [],
            static fn (string $flag): bool => $flag !== '',
        ));
    }

    /**
     * @param list<string> $lines
     * @return list<int>
     */
    private function parseSearchUids(array $lines): array
    {
        $uids = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\* SEARCH\b(.*)$/i', $line, $match)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($match[1])) ?: [] as $token) {
                if ($token !== '' && ctype_digit($token)) {
                    $uids[] = (int) $token;
                }
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function parsePermanentKeywords(array $lines): array
    {
        foreach ($lines as $line) {
            if (!preg_match('/\[PERMANENTFLAGS\s*\(([^)]*)\)\]/i', $line, $match)) {
                continue;
            }

            return $this->customKeywordsFromFlags($this->parseFlagList($match[1]));
        }

        return [];
    }
}
