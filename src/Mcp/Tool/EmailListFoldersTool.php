<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailListFoldersTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_list_folders';
    }

    public function getDescription(): string
    {
        return 'List IMAP folders for an email account with total/unread message counts.';
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
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Glob pattern, e.g. * or INBOX.*. Default: *',
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
        $pattern = (string) ($arguments['pattern'] ?? '*');

        if ($account === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "account" is required']],
                'isError' => true,
            ];
        }

        try {
            $folders = $this->emailService->listFolders($account, $pattern);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['folders' => $folders], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing folders: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
