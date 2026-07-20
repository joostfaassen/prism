<?php

namespace App\Integrations\Email\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Email\EmailService;

class EmailDeleteDraftTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_delete_draft';
    }

    public function getDescription(): string
    {
        return <<<TXT
            Permanently delete a draft from the account's IMAP Drafts folder (expunge — no trash).

            Only messages with the \\Draft flag are deleted; regular mail in the drafts folder is refused. Optionally pass `expected_message_id` (from email_create_draft) to guard against UID mix-ups between agent turns.

            This is the first step of replacing a draft: email_delete_draft, then email_create_draft with the corrected content. Does not affect sent messages or the original message being replied to.
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
                'uid' => [
                    'type' => 'integer',
                    'description' => 'UID of the draft within the Drafts folder (returned by email_create_draft, or found via email_search / email_get_messages on the drafts folder).',
                ],
                'drafts_folder' => [
                    'type' => 'string',
                    'description' => 'Override the IMAP Drafts folder. Defaults to the account\'s configured drafts_folder (usually "Drafts").',
                ],
                'expected_message_id' => [
                    'type' => 'string',
                    'description' => 'Optional safety guard: refuse if the message\'s Message-ID does not match (use the message_id from email_create_draft).',
                ],
            ],
            'required' => ['account', 'uid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        $uid = $arguments['uid'] ?? null;

        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if (!is_int($uid) || $uid <= 0) {
            return $this->error('Parameter "uid" is required and must be a positive integer');
        }

        try {
            $result = $this->emailService->deleteDraft(
                accountId: $account,
                uid: $uid,
                draftsFolderOverride: isset($arguments['drafts_folder']) ? (string) $arguments['drafts_folder'] : null,
                expectedMessageId: isset($arguments['expected_message_id']) ? (string) $arguments['expected_message_id'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error deleting draft: ' . $e->getMessage());
        }
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
