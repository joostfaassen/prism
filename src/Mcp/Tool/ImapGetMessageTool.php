<?php

namespace App\Mcp\Tool;

use App\Imap\ImapService;

class ImapGetMessageTool implements ToolInterface
{
    public function __construct(
        private readonly ImapService $imapService,
    ) {
    }

    public function getName(): string
    {
        return 'imap_get_message';
    }

    public function getDescription(): string
    {
        return 'Fetch the full content of an email message by UID, including body and attachment metadata';
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
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder name',
                ],
                'uid' => [
                    'type' => 'integer',
                    'description' => 'Message UID',
                ],
                'include_html' => [
                    'type' => 'boolean',
                    'description' => 'Include HTML body. Default: false',
                ],
                'max_body_chars' => [
                    'type' => 'integer',
                    'description' => 'Truncate body at N chars. Default: 8000',
                ],
            ],
            'required' => ['account', 'folder', 'uid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'imap';
    }

    public function execute(array $arguments): array
    {
        $account = $arguments['account'] ?? '';
        $folder = $arguments['folder'] ?? '';
        $uid = $arguments['uid'] ?? null;

        if ($account === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "account" is required']],
                'isError' => true,
            ];
        }

        if ($folder === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "folder" is required']],
                'isError' => true,
            ];
        }

        if ($uid === null || !is_int($uid)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "uid" is required and must be an integer']],
                'isError' => true,
            ];
        }

        try {
            $message = $this->imapService->getMessage(
                accountId: $account,
                folder: $folder,
                uid: $uid,
                includeHtml: $arguments['include_html'] ?? false,
                maxBodyChars: (int) ($arguments['max_body_chars'] ?? 8000),
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($message, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching message: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
