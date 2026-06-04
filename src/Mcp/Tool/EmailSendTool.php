<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailSendTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_send';
    }

    public function getDescription(): string
    {
        return <<<TXT
            Send an email — either a brand-new message or a reply to an existing message — over SMTP, and (by default) save a copy to the IMAP Sent folder so the conversation looks the same as if it were sent from a regular email client.

            The body is written in markdown and is automatically rendered as both a plain-text and an HTML alternative. When `reply_to` is supplied, the original message is fetched from IMAP, the new message is properly threaded (In-Reply-To and References headers), the subject is prefixed with "Re:" if needed, and the previous message is quoted at the bottom — exactly like Thunderbird or Apple Mail would do it.

            If you omit `to`/`cc`/`bcc` on a reply, recipients are derived from the original (use `reply_to.reply_all = true` to copy original To and Cc).

            This tool typically requires user approval before execution.
            TXT;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Email account ID to send from (see email_list_accounts).',
                ],
                'to' => [
                    'oneOf' => [
                        ['type' => 'string'],
                        ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'description' => 'Primary recipient(s). Either a single address, "Name <addr@example.com>", or a list of addresses. Optional on replies (then derived from the original).',
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
                    'description' => 'Blind carbon copy recipient(s). Optional.',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Subject line. Optional on replies — defaults to "Re: <original-subject>".',
                ],
                'body_markdown' => [
                    'type' => 'string',
                    'description' => 'Message body in markdown. Will be rendered as both plain text and HTML. On replies, this becomes the new content above the quoted original message.',
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
                    'description' => 'Reply context. When provided, the new message is threaded to the original.',
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
                'save_to_sent' => [
                    'type' => 'boolean',
                    'description' => 'Append a copy of the sent message to the IMAP Sent folder. Default: true.',
                ],
                'sent_folder' => [
                    'type' => 'string',
                    'description' => 'Override the IMAP folder used for the saved copy. Defaults to the account\'s configured sent_folder (or "Sent").',
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
            $result = $this->emailService->sendMessage(
                accountId: $account,
                to: $to,
                cc: $cc,
                bcc: $bcc,
                subject: isset($arguments['subject']) ? (string) $arguments['subject'] : null,
                bodyMarkdown: $bodyMarkdown,
                fromName: isset($arguments['from_name']) ? (string) $arguments['from_name'] : null,
                replyToOverride: isset($arguments['reply_to_address']) ? (string) $arguments['reply_to_address'] : null,
                replyTo: $replyTo,
                saveToSent: (bool) ($arguments['save_to_sent'] ?? true),
                sentFolderOverride: isset($arguments['sent_folder']) ? (string) $arguments['sent_folder'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error sending email: ' . $e->getMessage());
        }
    }

    /**
     * Accept either a string or list-of-strings for recipients. Empty values
     * are filtered out. Returns a clean list<string>.
     *
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
