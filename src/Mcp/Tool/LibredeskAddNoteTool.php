<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskAddNoteTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_add_note';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Add an internal private note to a Libredesk conversation (identified by UUID).

The note is visible only to agents and is NEVER sent to the contact. Use this
for internal handover comments, context, or reasoning. To reply to the contact,
use libredesk_reply instead.
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Libredesk account key',
                ],
                'uuid' => [
                    'type' => 'string',
                    'description' => 'Conversation UUID to add the note to',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Note body (HTML or plain text)',
                ],
            ],
            'required' => ['account', 'uuid', 'message'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $uuid = $arguments['uuid'] ?? '';
        $message = $arguments['message'] ?? '';

        if ($accountKey === '' || $uuid === '' || $message === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account", "uuid", and "message" are required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->libredeskService->sendMessage(
                accountKey: $accountKey,
                uuid: $uuid,
                message: $message,
                private: true,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    ['success' => true, 'message' => $result],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
