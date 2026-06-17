<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskReplyTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_reply';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Send a reply to the contact on a Libredesk conversation (identified by UUID).

This message IS delivered to the contact (sender_type "agent"). To add an
internal note that is only visible to agents, use libredesk_add_note instead.

Optionally override the to/cc/bcc recipients. If omitted, Libredesk uses the
conversation's existing recipients.
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
                    'description' => 'Conversation UUID to reply to',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Reply body (HTML or plain text)',
                ],
                'to' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Email recipients (optional override)',
                ],
                'cc' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'CC recipients (optional)',
                ],
                'bcc' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'BCC recipients (optional)',
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

        $to = $this->stringList($arguments['to'] ?? []);
        $cc = $this->stringList($arguments['cc'] ?? []);
        $bcc = $this->stringList($arguments['bcc'] ?? []);

        try {
            $result = $this->libredeskService->sendMessage(
                accountKey: $accountKey,
                uuid: $uuid,
                message: $message,
                private: false,
                to: $to,
                cc: $cc,
                bcc: $bcc,
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

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
