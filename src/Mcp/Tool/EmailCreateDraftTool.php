<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailCreateDraftTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_create_draft';
    }

    public function getDescription(): string
    {
        return <<<TXT
            Stage a draft email in the account's IMAP Drafts folder — never sends it.

            The draft is a complete message (multipart text+HTML from markdown), identical to what Thunderbird would save: flags \\Seen \\Draft, optional Bcc preserved, and when `reply_to` is supplied the draft is properly threaded (In-Reply-To / References), subject prefixed with "Re:" if needed, and the original message quoted. The human then opens their mail client, reviews/edits, and hits Send.

            Recipients may be omitted (including for non-replies); on replies without `to`/`cc`, recipients are derived from the original (use `reply_to.reply_all = true` for reply-all). SMTP is not required — read-only IMAP accounts can stage drafts too.

            To replace an existing draft: call email_delete_draft first, then email_create_draft again. Use email_list_folders if the drafts folder name is unclear (override with `drafts_folder`).
            TXT;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Email account ID (see email_list_accounts).',
                ],
                'to' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'description' => 'Primary recipient(s). Optional — can be filled in later in the mail client. On replies, derived from the original when omitted.',
                ],
                'cc' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'description' => 'Carbon copy recipient(s). Optional.',
                ],
                'bcc' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'description' => 'Blind carbon copy recipient(s). Preserved in the draft so the mail client can restore them.',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Subject line. Optional on replies — defaults to "Re: <original-subject>".',
                ],
                'body_markdown' => [
                    'type' => 'string',
                    'description' => 'Message body in markdown. Rendered as both plain text and HTML. On replies, this becomes the new content above the quoted original.',
                ],
                'from_name' => [
                    'type' => 'string',
                    'description' => 'Optional override of the From display name (the address always comes from the account config).',
                ],
                'reply_to_address' => [
                    'type' => 'string',
                    'description' => 'Optional Reply-To header — replies will go to this address instead of the From address.',
                ],
                'reply_to' => [
                    'type' => 'object',
                    'description' => 'Reply context. When provided, the draft is threaded to the original message.',
                    'properties' => [
                        'folder' => [
                            'type' => 'string',
                            'description' => 'Folder containing the original message.',
                        ],
                        'uid' => [
                            'type' => 'integer',
                            'description' => 'UID of the original message in that folder.',
                        ],
                        'reply_all' => [
                            'type' => 'boolean',
                            'description' => 'If true and `to`/`cc` are not explicitly given, reply to original sender + To + Cc. Default: false.',
                        ],
                    ],
                    'required' => ['folder', 'uid'],
                ],
                'drafts_folder' => [
                    'type' => 'string',
                    'description' => 'Override the IMAP Drafts folder. Defaults to the account\'s configured drafts_folder (usually "Drafts"). Use email_list_folders to find the exact name.',
                ],
            ],
            'required' => ['account', 'body_markdown'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        $bodyMarkdown = (string) ($arguments['body_markdown'] ?? '');

        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if ($bodyMarkdown === '') {
            return $this->error('Parameter "body_markdown" is required and must be a non-empty string');
        }

        try {
            $to = $this->normalizeRecipients($arguments['to'] ?? null);
            $cc = $this->normalizeRecipients($arguments['cc'] ?? null);
            $bcc = $this->normalizeRecipients($arguments['bcc'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        $replyTo = null;
        $rawReplyTo = $arguments['reply_to'] ?? null;
        if (is_array($rawReplyTo)) {
            $folder = (string) ($rawReplyTo['folder'] ?? '');
            $uid = $rawReplyTo['uid'] ?? null;

            if ($folder === '') {
                return $this->error('reply_to.folder is required when reply_to is provided');
            }

            if (!is_int($uid)) {
                return $this->error('reply_to.uid must be an integer');
            }

            $replyTo = [
                'folder' => $folder,
                'uid' => $uid,
                'reply_all' => (bool) ($rawReplyTo['reply_all'] ?? false),
            ];
        }

        try {
            $result = $this->emailService->createDraft(
                accountId: $account,
                to: $to,
                cc: $cc,
                bcc: $bcc,
                subject: isset($arguments['subject']) ? (string) $arguments['subject'] : null,
                bodyMarkdown: $bodyMarkdown,
                fromName: isset($arguments['from_name']) ? (string) $arguments['from_name'] : null,
                replyToOverride: isset($arguments['reply_to_address']) ? (string) $arguments['reply_to_address'] : null,
                replyTo: $replyTo,
                draftsFolderOverride: isset($arguments['drafts_folder']) ? (string) $arguments['drafts_folder'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error creating draft: ' . $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeRecipients(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? [] : [$value];
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('Recipient fields must be a string or an array of strings.');
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException('Recipient list must contain only strings.');
            }

            $entry = trim($entry);

            if ($entry !== '') {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: true}
     */
    private function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
