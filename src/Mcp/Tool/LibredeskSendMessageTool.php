<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskSendMessageTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_send_message';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Send a message on a Libredesk conversation (identified by UUID).

- Default: a reply sent to the contact (sender_type "agent").
- Set private=true to add an internal note visible only to agents.

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
                    'description' => 'Conversation UUID to post the message to',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Message body (HTML or plain text)',
                ],
                'private' => [
                    'type' => 'boolean',
                    'description' => 'Set to true for an internal note (default: false = reply to contact)',
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

        $private = (bool) ($arguments['private'] ?? false);
        $to = $this->stringList($arguments['to'] ?? []);
        $cc = $this->stringList($arguments['cc'] ?? []);
        $bcc = $this->stringList($arguments['bcc'] ?? []);

        try {
            $result = $this->libredeskService->sendMessage(
                accountKey: $accountKey,
                uuid: $uuid,
                message: $message,
                private: $private,
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
