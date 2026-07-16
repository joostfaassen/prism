<?php

namespace App\Email;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Top-level entry point for the `email` integration. Wraps the IMAP client,
 * SMTP mailer, message composer and reply context loader behind a small set
 * of methods that map 1:1 to the MCP tools.
 */
class EmailService
{
    public function __construct(
        private readonly EmailConfigLoader $configLoader,
        private readonly ImapClient $imap,
        private readonly SmtpMailer $smtp,
        private readonly MessageComposer $composer,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccounts(): array
    {
        $result = [];
        foreach ($this->configLoader->getAccounts() as $account) {
            $result[] = $this->imap->listAccountSummary($account);
        }

        return $result;
    }

    /**
     * @return list<array{name: string, delimiter: string, total: int, unseen: int}>
     */
    public function listFolders(string $accountId, string $pattern = '*'): array
    {
        return $this->imap->listFolders(
            $this->configLoader->getAccount($accountId),
            $pattern,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function listLabels(
        string $accountId,
        string $folder = 'INBOX',
        bool $includeFolders = true,
        bool $includeKeywords = true,
        int $messageLimit = 1000,
    ): array {
        return $this->imap->listLabels(
            $this->configLoader->getAccount($accountId),
            $folder,
            $includeFolders,
            $includeKeywords,
            $messageLimit,
        );
    }

    /**
     * Read IMAP flags and custom keyword tags/labels for one message.
     *
     * @return array<string, mixed>
     */
    public function getMessageLabels(
        string $accountId,
        string $folder,
        int $uid,
    ): array {
        return $this->imap->getMessageLabels(
            $this->configLoader->getAccount($accountId),
            $folder,
            $uid,
        );
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
        bool $includeDeleted = false,
    ): array {
        return $this->imap->search(
            $this->configLoader->getAccount($accountId),
            $folder,
            $from,
            $to,
            $subject,
            $body,
            $since,
            $before,
            $unseenOnly,
            $flaggedOnly,
            $limit,
            $offset,
            $includeDeleted,
        );
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
        return $this->imap->getMessage(
            $this->configLoader->getAccount($accountId),
            $folder,
            $uid,
            $includeHtml,
            $maxBodyChars,
        );
    }

    /**
     * Fetch the complete, unparsed RFC822/MIME source for a single message,
     * e.g. so clients can extract attachments or inspect raw headers.
     */
    public function getRawMessage(string $accountId, string $folder, int $uid): string
    {
        return $this->imap->getRawMessage(
            $this->configLoader->getAccount($accountId),
            $folder,
            $uid,
        );
    }

    /**
     * @param list<int> $uids
     * @return list<array<string, mixed>>
     */
    public function getMessages(
        string $accountId,
        string $folder,
        array $uids,
        bool $includeHtml = false,
        int $maxBodyChars = 8000,
    ): array {
        return $this->imap->getMessages(
            $this->configLoader->getAccount($accountId),
            $folder,
            $uids,
            $includeHtml,
            $maxBodyChars,
        );
    }

    /**
     * @param list<string> $folders
     * @return array{queries: list<array{folder: string, total: int, offset: int, messages: list<array<string,mixed>>}>}
     */
    public function multiSearch(
        string $accountId,
        array $folders,
        ?string $from = null,
        ?string $to = null,
        ?string $subject = null,
        ?string $body = null,
        ?string $since = null,
        ?string $before = null,
        bool $unseenOnly = false,
        bool $flaggedOnly = false,
        int $limitPerFolder = 20,
        int $offset = 0,
        bool $includeDeleted = false,
    ): array {
        return $this->imap->multiSearch(
            $this->configLoader->getAccount($accountId),
            $folders,
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
    }

    /**
     * @return array{folder: string, warmed: int, inspected: int, cached: int}
     */
    public function warmRecentCache(
        string $accountId,
        string $folder = 'INBOX',
        int $days = 7,
        int $limit = 200,
        ?callable $onProgress = null,
    ): array {
        return $this->imap->warmRecentCache(
            $this->configLoader->getAccount($accountId),
            $folder,
            $days,
            $limit,
            $onProgress,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function moveMessage(string $accountId, string $fromFolder, int $uid, string $toFolder): array
    {
        return $this->imap->moveMessage(
            $this->configLoader->getAccount($accountId),
            $fromFolder,
            $uid,
            $toFolder,
        );
    }

    /**
     * @param array<string, bool|null> $standardFlags
     * @param list<string>             $addLabels
     * @param list<string>             $removeLabels
     *
     * @return array<string, mixed>
     */
    public function updateMessageFlags(
        string $accountId,
        string $folder,
        int $uid,
        array $standardFlags,
        array $addLabels,
        array $removeLabels,
    ): array {
        return $this->imap->updateMessageFlags(
            $this->configLoader->getAccount($accountId),
            $folder,
            $uid,
            $standardFlags,
            $addLabels,
            $removeLabels,
        );
    }

    /**
     * Send a new message or reply, optionally persisting a copy to the Sent
     * folder.
     *
     * @param list<string>             $to
     * @param list<string>             $cc
     * @param list<string>             $bcc
     * @param array{folder: string, uid: int, reply_all?: bool}|null $replyTo
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        string $accountId,
        array $to,
        array $cc,
        array $bcc,
        ?string $subject,
        string $bodyMarkdown,
        ?string $fromName,
        ?string $replyToOverride,
        ?array $replyTo,
        bool $saveToSent,
        ?string $sentFolderOverride,
    ): array {
        $account = $this->configLoader->getAccount($accountId);

        if (!$account->hasSmtp()) {
            throw new \RuntimeException(sprintf(
                'Email account "%s" has no SMTP configured. Add an `smtp:` block to the account in prism.config.yaml.',
                $accountId,
            ));
        }

        $resolved = $this->resolveReply($account, $replyTo, $to, $cc);
        $to = $resolved['to'];
        $cc = $resolved['cc'];
        $context = $resolved['context'];

        if ($to === [] && $cc === [] && $bcc === []) {
            throw new \InvalidArgumentException('At least one recipient is required (to, cc, or bcc).');
        }

        $composed = $this->composer->compose(
            $account,
            $to,
            $cc,
            $bcc,
            $subject,
            $bodyMarkdown,
            $fromName,
            $replyToOverride,
            $context,
        );

        // Materialize the message once: this fixes Message-ID, Date and the
        // canonical MIME bytes so the copy that goes to Sent matches what
        // the recipient receives.
        $rawMessage = $composed->email->toString();
        $messageId = $this->extractMessageId(
            $composed->email->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '',
        );

        $this->smtp->send($account, $composed->email);

        $sentFolder = $sentFolderOverride ?? $account->sentFolder;
        $savedToSent = false;
        $warning = null;

        if ($saveToSent) {
            try {
                $this->imap->appendToFolder($account, $sentFolder, $rawMessage, '\\Seen');
                $savedToSent = true;
            } catch (\Throwable $e) {
                $warning = 'Message was sent but could not be saved to Sent folder: ' . $e->getMessage();
            }
        }

        $result = [
            'sent' => true,
            'message_id' => $messageId,
            'subject' => $composed->subject,
            'to' => $composed->to,
            'cc' => $composed->cc,
            'bcc' => $composed->bcc,
            'in_reply_to' => $composed->inReplyTo,
            'saved_to_sent' => $savedToSent,
            'sent_folder' => $savedToSent ? $sentFolder : null,
        ];

        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
    }

    /**
     * Stage a draft in the IMAP Drafts folder (never sends). Optionally a reply
     * to an existing message — same threading/quoting as email_send.
     *
     * @param list<string>             $to
     * @param list<string>             $cc
     * @param list<string>             $bcc
     * @param array{folder: string, uid: int, reply_all?: bool}|null $replyTo
     *
     * @return array<string, mixed>
     */
    public function createDraft(
        string $accountId,
        array $to,
        array $cc,
        array $bcc,
        ?string $subject,
        string $bodyMarkdown,
        ?string $fromName,
        ?string $replyToOverride,
        ?array $replyTo,
        ?string $draftsFolderOverride,
    ): array {
        $account = $this->configLoader->getAccount($accountId);

        $resolved = $this->resolveReply($account, $replyTo, $to, $cc);
        $to = $resolved['to'];
        $cc = $resolved['cc'];
        $context = $resolved['context'];

        $composed = $this->composer->compose(
            $account,
            $to,
            $cc,
            $bcc,
            $subject,
            $bodyMarkdown,
            $fromName,
            $replyToOverride,
            $context,
        );

        $messageId = $this->extractMessageId(
            $composed->email->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '',
        );
        $rawMessage = $this->materializeDraft($composed->email);

        $draftsFolder = $draftsFolderOverride ?? $account->draftsFolder;
        $uid = $this->imap->appendToFolder(
            $account,
            $draftsFolder,
            $rawMessage,
            '\\Seen \\Draft',
            $messageId,
        );

        $result = [
            'created' => true,
            'folder' => $draftsFolder,
            'uid' => $uid,
            'message_id' => $messageId,
            'subject' => $composed->subject,
            'to' => $composed->to,
            'cc' => $composed->cc,
            'bcc' => $composed->bcc,
            'in_reply_to' => $composed->inReplyTo,
        ];

        if ($uid === null) {
            $result['warning'] = sprintf(
                'Draft was saved to "%s" but its UID could not be resolved. Locate it with email_search on that folder (e.g. by subject or Message-ID).',
                $draftsFolder,
            );
        }

        return $result;
    }

    /**
     * Permanently delete a draft from the IMAP Drafts folder.
     *
     * @return array<string, mixed>
     */
    public function deleteDraft(
        string $accountId,
        int $uid,
        ?string $draftsFolderOverride,
        ?string $expectedMessageId,
    ): array {
        $account = $this->configLoader->getAccount($accountId);
        $draftsFolder = $draftsFolderOverride ?? $account->draftsFolder;

        $deleted = $this->imap->deleteMessage(
            $account,
            $draftsFolder,
            $uid,
            requireDraftFlag: true,
            expectedMessageId: $expectedMessageId,
        );

        return [
            'deleted' => true,
            'folder' => $draftsFolder,
            'uid' => $uid,
            'message_id' => $deleted['message_id'] ?? null,
            'subject' => $deleted['subject'] ?? '',
        ];
    }

    /**
     * @param array{folder: string, uid: int, reply_all?: bool}|null $replyTo
     * @param list<string> $to
     * @param list<string> $cc
     *
     * @return array{context: ?ReplyContext, to: list<string>, cc: list<string>}
     */
    private function resolveReply(EmailAccountConfig $account, ?array $replyTo, array $to, array $cc): array
    {
        if ($replyTo === null) {
            return ['context' => null, 'to' => $to, 'cc' => $cc];
        }

        $folder = (string) ($replyTo['folder'] ?? 'INBOX');
        $uid = (int) ($replyTo['uid'] ?? 0);
        $replyAll = (bool) ($replyTo['reply_all'] ?? false);

        if ($uid <= 0) {
            throw new \InvalidArgumentException('reply_to.uid must be a positive integer');
        }

        $original = $this->imap->getMessageForReply($account, $folder, $uid);
        $context = ReplyContext::fromImapMessage($original);

        if ($to === [] && $cc === []) {
            $extraReplyTo = $original['reply_to'] ?? [];
            $autoRecipients = $replyAll
                ? $this->composer->buildReplyAllRecipients($account, $context, $extraReplyTo)
                : ['to' => $this->pickPrimaryReplyAddress($context, $extraReplyTo, $account), 'cc' => []];

            $to = $autoRecipients['to'];
            $cc = $autoRecipients['cc'];
        }

        return ['context' => $context, 'to' => $to, 'cc' => $cc];
    }

    /**
     * Materialize draft MIME bytes with Bcc preserved (unlike Email::toString(),
     * which strips Bcc for the on-the-wire send path).
     */
    private function materializeDraft(Email $email): string
    {
        $headers = $email->getPreparedHeaders();

        if ($email->getBcc() !== []) {
            $headers->addMailboxListHeader('Bcc', $email->getBcc());
        }

        $body = $email->getBody() ?? new TextPart('');

        return $headers->toString() . $body->toString();
    }

    /**
     * @param list<array{name: string|null, address: string}> $extraReplyTo
     * @return list<string>
     */
    private function pickPrimaryReplyAddress(ReplyContext $context, array $extraReplyTo, EmailAccountConfig $account): array
    {
        $self = strtolower($account->getFromAddress());

        $candidates = $extraReplyTo !== [] ? $extraReplyTo : [$context->originalFrom];

        foreach ($candidates as $addr) {
            $email = $addr['address'] ?? '';
            if ($email !== '' && strtolower($email) !== $self) {
                return [$email];
            }
        }

        return [];
    }

    private function extractMessageId(string $rawHeader): ?string
    {
        $trimmed = trim($rawHeader);

        if ($trimmed === '') {
            return null;
        }

        if ($trimmed[0] === '<' && str_ends_with($trimmed, '>')) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }
}
