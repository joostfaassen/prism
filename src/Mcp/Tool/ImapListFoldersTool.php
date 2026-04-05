<?php

namespace App\Mcp\Tool;

use App\Imap\ImapService;

class ImapListFoldersTool implements ToolInterface
{
    public function __construct(
        private readonly ImapService $imapService,
    ) {
    }

    public function getName(): string
    {
        return 'imap_list_folders';
    }

    public function getDescription(): string
    {
        return 'List folders for an IMAP account with unread counts';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Account ID',
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
        return 'imap';
    }

    public function execute(array $arguments): array
    {
        $account = $arguments['account'] ?? '';
        $pattern = $arguments['pattern'] ?? '*';

        if ($account === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "account" is required']],
                'isError' => true,
            ];
        }

        try {
            $folders = $this->imapService->listFolders($account, $pattern);

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
