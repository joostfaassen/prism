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

        return $result;
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
     * @return list<array<string, mixed>>
     */
    public function getMessages(
        EmailAccountConfig $account,
        string $folder,
        array $uids,
        bool $includeHtml = false,
        int $maxBodyChars = 8000,
        ?callable $onProgress = null,
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

            foreach ($uids as $uid) {
                $cached = $this->messageCache->getMessageBody(
                    $account->id,
                    $folder,
                    $signature->uidValidity,
                    $uid,
                    $includeHtml,
                    $maxBodyChars,
                );
                if ($cached === null) {
                    $missUids[] = $uid;
                    continue;
                }

                $flags = $this->messageCache->getMessageFlags($account->id, $folder, $signature->uidValidity, $uid);
                if ($flags !== null) {
                    $cached['seen'] = $flags['seen'];
                    $cached['flagged'] = $flags['flagged'];
                    $cached['answered'] = $flags['answered'];
                }

                $resultByUid[$uid] = $cached;
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
        foreach ($search['messages'] as $message) {
            $uid = (int) ($message['uid'] ?? 0);
            if ($uid > 0) {
                $uids[] = $uid;
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
        $conn = $this->connect($account, $folder);

        try {
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

            if ($setFlags !== []) {
                $this->setFlags($conn, $uid, $setFlags);
            }
            if ($unsetFlags !== []) {
                $this->unsetFlags($conn, $uid, $unsetFlags);
            }

            return [
                'updated' => true,
                'uid' => $uid,
                'folder' => $folder,
                'set_flags' => $setFlags,
                'unset_flags' => $unsetFlags,
                'message' => $this->fetchMessageSummaries($conn, [$uid])[0] ?? ['uid' => $uid],
            ];
        } finally {
            imap_close($conn);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessageForReply(EmailAccountConfig $account, string $folder, int $uid): array
    {
        return $this->getMessage($account, $folder, $uid, includeHtml: true, maxBodyChars: 200000);
    }

    public function appendToFolder(
        EmailAccountConfig $account,
        string $folder,
        string $rawMessage,
        string $flags = '\\Seen',
    ): void {
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

            $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $rawMessage) ?? $rawMessage;
            if (!@imap_append($conn, $folderPath, $normalized, $flags)) {
                $errors = imap_errors() ?: [];
                throw new \RuntimeException(sprintf(
                    'Failed to append message to folder "%s": %s',
                    $folder,
                    implode('; ', $errors),
                ));
            }
        } finally {
            imap_close($conn);
        }
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
                'seen' => (bool) ($item->seen ?? false),
                'flagged' => (bool) ($item->flagged ?? false),
                'answered' => (bool) ($item->answered ?? false),
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
    ): string {
        $parts = [];
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
}
