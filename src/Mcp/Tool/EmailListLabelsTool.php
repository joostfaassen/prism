<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailListLabelsTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_list_labels';
    }

    public function getDescription(): string
    {
        return 'List available email labels for an account. Returns IMAP folders plus standard flags and, where supported, custom IMAP keywords found on messages.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Email account ID.',
                ],
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder to scan for custom IMAP keywords. Default: INBOX',
                ],
                'include_folders' => [
                    'type' => 'boolean',
                    'description' => 'Include IMAP folders/mailboxes as provider labels. Default: true',
                ],
                'include_keywords' => [
                    'type' => 'boolean',
                    'description' => 'Inspect message flags for custom IMAP keywords. Default: true',
                ],
                'message_limit' => [
                    'type' => 'integer',
                    'description' => 'Max messages to scan for custom keywords in the folder. Default: 1000, max: 5000',
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        $folder = (string) ($arguments['folder'] ?? 'INBOX');

        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if ($folder === '') {
            return $this->error('Parameter "folder" must be a non-empty string');
        }

        $messageLimit = max(0, min((int) ($arguments['message_limit'] ?? 1000), 5000));

        try {
            $result = $this->emailService->listLabels(
                accountId: $account,
                folder: $folder,
                includeFolders: (bool) ($arguments['include_folders'] ?? true),
                includeKeywords: (bool) ($arguments['include_keywords'] ?? true),
                messageLimit: $messageLimit,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error listing labels: ' . $e->getMessage());
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
