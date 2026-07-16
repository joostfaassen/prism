<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailCreateFolderTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_create_folder';
    }

    public function getDescription(): string
    {
        return 'Create a new IMAP folder on an email account. Returns an error if the folder already exists.';
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
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder name to create, e.g. Archive or INBOX.Projects. Must not already exist.',
                ],
            ],
            'required' => ['account', 'folder'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        $folder = (string) ($arguments['folder'] ?? '');

        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if (trim($folder) === '') {
            return $this->error('Parameter "folder" is required');
        }

        try {
            $result = $this->emailService->createFolder($account, $folder);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error creating folder: ' . $e->getMessage());
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
