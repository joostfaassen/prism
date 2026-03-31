<?php

namespace App\Imap;

class ImapService
{
    public function __construct(
        private readonly ImapConfigLoader $configLoader,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, host: string, username: string}>
     */
    public function listAccounts(): array
    {
        $result = [];

        foreach ($this->configLoader->getAccounts() as $account) {
            $result[] = [
                'id' => $account->id,
                'label' => $account->label,
                'host' => $account->host,
                'username' => $account->username,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{name: string, delimiter: string, total: int, unseen: int}>
     */
    public function listFolders(string $accountId, string $pattern = '*'): array
    {
        $account = $this->configLoader->getAccount($accountId);
        $conn = $this->connect($account);

        try {
            $serverStr = $account->getServerString();
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
     * @return array{total: int, offset: int, messages: list<array<string, mixed>>}
     */
    public function search(
        string $accountId,
        string $folder = 'INBOX',
        ?string $from = null,
        ?string $to = null,
        ?string $subject = null,
        ?string $body = null,
        ?string $since = null,
        ?string $before = null,
        bool $unseenOnly = false,
        bool $flaggedOnly = false,
        int $limit = 20,
        int $offset = 0,
    ): array {
        $account = $this->configLoader->getAccount($accountId);
        $conn = $this->connect($account, $folder);

        try {
            $criteria = $this->buildSearchCriteria(
                $from, $to, $subject, $body, $since, $before, $unseenOnly, $flaggedOnly,
            );

            $uids = imap_search($conn, $criteria, SE_UID);

            if ($uids === false) {
                return ['total' => 0, 'offset' => $offset, 'messages' => []];
            }

            rsort($uids, SORT_NUMERIC);
            $total = count($uids);
            $slice = array_slice($uids, $offset, min($limit, 100));

            $messages = [];
            foreach ($slice as $uid) {
                $messages[] = $this->fetchMessageSummary($conn, $uid);
            }

            return ['total' => $total, 'offset' => $offset, 'messages' => $messages];
        } finally {
            imap_close($conn);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessage(
        string $accountId,
        string $folder,
        int $uid,
        bool $includeHtml = false,
        int $maxBodyChars = 8000,
    ): array {
        $account = $this->configLoader->getAccount($accountId);
        $conn = $this->connect($account, $folder);

        try {
            $overview = imap_fetch_overview($conn, (string) $uid, FT_UID);

            if (empty($overview)) {
                throw new \RuntimeException(sprintf('Message UID %d not found in %s', $uid, $folder));
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

            if ($includeHtml && $bodyHtml !== null && strlen($bodyHtml) > $maxBodyChars) {
                $bodyHtml = mb_substr($bodyHtml, 0, $maxBodyChars) . "\n[truncated]";
            }

            return [
                'uid' => $uid,
                'message_id' => $header->message_id ?? null,
                'in_reply_to' => $parsedHeader->in_reply_to ?? null,
                'date' => isset($header->date) ? (new \DateTimeImmutable($header->date))->format('c') : null,
                'from' => $this->parseAddress($parsedHeader->from ?? []),
                'to' => $this->parseAddressList($parsedHeader->to ?? []),
                'cc' => $this->parseAddressList($parsedHeader->cc ?? []),
                'subject' => isset($header->subject) ? $this->decodeMime($header->subject) : '',
                'body_text' => $bodyText,
                'body_html' => $includeHtml ? $bodyHtml : null,
                'seen' => (bool) ($header->seen ?? false),
                'flagged' => (bool) ($header->flagged ?? false),
                'attachments' => $attachments,
            ];
        } finally {
            imap_close($conn);
        }
    }

    /**
     * @return \IMAP\Connection
     */
    private function connect(ImapAccountConfig $account, string $folder = 'INBOX'): \IMAP\Connection
    {
        $mailbox = $account->getMailboxString($folder);
        $conn = @imap_open($mailbox, $account->username, $account->password, 0, 3);

        if ($conn === false) {
            $errors = imap_errors() ?: [];
            throw new \RuntimeException(sprintf(
                'Failed to connect to IMAP account "%s": %s',
                $account->id,
                implode('; ', $errors),
            ));
        }

        return $conn;
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

        return empty($parts) ? 'ALL' : implode(' ', $parts);
    }

    /**
     * @return array{uid: int, date: string|null, from: array{name: string|null, address: string}, to: list<array{name: string|null, address: string}>, subject: string, seen: bool, flagged: bool, has_attachments: bool, size: int}
     */
    private function fetchMessageSummary(\IMAP\Connection $conn, int $uid): array
    {
        $overview = imap_fetch_overview($conn, (string) $uid, FT_UID);

        if (empty($overview)) {
            throw new \RuntimeException(sprintf('Message UID %d not found', $uid));
        }

        $header = $overview[0];
        $headerText = imap_fetchheader($conn, $uid, FT_UID);
        $parsedHeader = imap_rfc822_parse_headers($headerText);
        $structure = imap_fetchstructure($conn, $uid, FT_UID);

        $hasAttachments = $this->hasAttachments($structure);

        return [
            'uid' => $uid,
            'date' => isset($header->date) ? (new \DateTimeImmutable($header->date))->format('c') : null,
            'from' => $this->parseAddress($parsedHeader->from ?? []),
            'to' => $this->parseAddressList($parsedHeader->to ?? []),
            'subject' => isset($header->subject) ? $this->decodeMime($header->subject) : '',
            'seen' => (bool) ($header->seen ?? false),
            'flagged' => (bool) ($header->flagged ?? false),
            'has_attachments' => $hasAttachments,
            'size' => (int) ($header->size ?? 0),
        ];
    }

    /**
     * @param list<object> $addresses
     * @return array{name: string|null, address: string}
     */
    private function parseAddress(array $addresses): array
    {
        if (empty($addresses)) {
            return ['name' => null, 'address' => ''];
        }

        $addr = $addresses[0];
        $mailbox = $addr->mailbox ?? '';
        $host = $addr->host ?? '';
        $personal = isset($addr->personal) ? $this->decodeMime($addr->personal) : null;

        return [
            'name' => $personal,
            'address' => $host ? "{$mailbox}@{$host}" : $mailbox,
        ];
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
            $personal = isset($addr->personal) ? $this->decodeMime($addr->personal) : null;

            $result[] = [
                'name' => $personal,
                'address' => $host ? "{$mailbox}@{$host}" : $mailbox,
            ];
        }

        return $result;
    }

    private function decodeMime(string $text): string
    {
        $decoded = imap_mime_header_decode($text);
        $result = '';

        foreach ($decoded as $part) {
            $result .= $part->text;
        }

        return $result;
    }

    private function hasAttachments(\stdClass $structure): bool
    {
        if (!empty($structure->parts)) {
            foreach ($structure->parts as $part) {
                $disposition = $part->ifdisposition ? strtolower($part->disposition) : '';
                if ($disposition === 'attachment') {
                    return true;
                }
                if (isset($part->parts) && $this->hasAttachments($part)) {
                    return true;
                }
            }
        }

        return false;
    }

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
                $subPartNumber = $partNumber ? ($partNumber . '.' . ($index + 1)) : (string) ($index + 1);
                $this->extractParts($conn, $uid, $part, $subPartNumber, $bodyText, $bodyHtml, $attachments);
            }
            return;
        }

        $disposition = $structure->ifdisposition ? strtolower($structure->disposition) : '';

        if ($disposition === 'attachment') {
            $filename = 'unnamed';
            if ($structure->ifdparameters) {
                foreach ($structure->dparameters as $param) {
                    if (strtolower($param->attribute) === 'filename') {
                        $filename = $this->decodeMime($param->value);
                        break;
                    }
                }
            } elseif ($structure->ifparameters) {
                foreach ($structure->parameters as $param) {
                    if (strtolower($param->attribute) === 'name') {
                        $filename = $this->decodeMime($param->value);
                        break;
                    }
                }
            }

            $attachments[] = [
                'filename' => $filename,
                'mime_type' => $this->getMimeType($structure),
                'size' => $structure->bytes ?? 0,
            ];

            return;
        }

        $type = $structure->type ?? 0;
        $subtype = strtolower($structure->subtype ?? '');

        if ($type === 0 && $subtype === 'plain' && $bodyText === null) {
            $bodyText = $this->fetchDecodedBody($conn, $uid, $partNumber ?: '1', $structure->encoding ?? 0);
        } elseif ($type === 0 && $subtype === 'html' && $bodyHtml === null) {
            $bodyHtml = $this->fetchDecodedBody($conn, $uid, $partNumber ?: '1', $structure->encoding ?? 0);
        }
    }

    private function fetchDecodedBody(\IMAP\Connection $conn, int $uid, string $partNumber, int $encoding): string
    {
        $body = imap_fetchbody($conn, $uid, $partNumber, FT_UID);

        return match ($encoding) {
            3 => base64_decode($body) ?: '',  // BASE64
            4 => quoted_printable_decode($body),  // QUOTED-PRINTABLE
            default => $body,
        };
    }

    private function getMimeType(\stdClass $structure): string
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];
        $type = $types[$structure->type ?? 0] ?? 'application';
        $subtype = strtolower($structure->subtype ?? 'octet-stream');

        return "{$type}/{$subtype}";
    }
}
