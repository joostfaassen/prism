<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailGetMessagesTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_get_messages';
    }

    public function getDescription(): string
    {
        return 'Fetch full content for messages in bulk from a folder using an array of UIDs.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Email account ID',
                ],
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder name',
                ],
                'uids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'List of message UIDs to fetch',
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
            'required' => ['account', 'folder', 'uids'],
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
        $uids = $arguments['uids'] ?? null;
        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if ($folder === '') {
            return $this->error('Parameter "folder" is required');
        }

        if (!is_array($uids) || $uids === []) {
            return $this->error('Parameter "uids" is required and must be a non-empty array of integers');
        }

        $normalizedUids = [];
        foreach ($uids as $uid) {
            if (!is_int($uid) || $uid <= 0) {
                return $this->error('Parameter "uids" must contain only positive integers');
            }
            $normalizedUids[] = $uid;
        }

        try {
            $messages = $this->emailService->getMessages(
                accountId: $account,
                folder: $folder,
                uids: $normalizedUids,
                includeHtml: (bool) ($arguments['include_html'] ?? false),
                maxBodyChars: (int) ($arguments['max_body_chars'] ?? 8000),
            );

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode(['messages' => $messages], JSON_THROW_ON_ERROR),
                ]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error fetching messages: ' . $e->getMessage());
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
