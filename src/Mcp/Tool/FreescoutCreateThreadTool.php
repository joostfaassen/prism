<?php

namespace App\Mcp\Tool;

use App\Freescout\FreescoutService;

class FreescoutCreateThreadTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_create_thread';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Create a thread (reply, note, or draft) on a Freescout conversation.

Types:
- "message": A reply sent to the customer (default)
- "note": An internal note visible only to agents

Set state to "draft" to save as draft without sending.
Set status to change the conversation status after posting (e.g. "closed", "active", "pending").
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Freescout account key',
                ],
                'conversation_id' => [
                    'type' => 'integer',
                    'description' => 'Conversation ID to add the thread to',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Message body (plain text or HTML)',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'Thread type: "message" (reply to customer, default) or "note" (internal)',
                    'enum' => ['message', 'note'],
                ],
                'state' => [
                    'type' => 'string',
                    'description' => 'Set to "draft" to save without sending. Omit or "published" to send immediately.',
                    'enum' => ['draft', 'published'],
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Change conversation status after posting: active, pending, or closed',
                    'enum' => ['active', 'pending', 'closed'],
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'User ID to post as. Use freescout_list_users to find IDs. If omitted, uses the API key owner.',
                ],
            ],
            'required' => ['account', 'conversation_id', 'text'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'freescout';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $conversationId = isset($arguments['conversation_id']) ? (int) $arguments['conversation_id'] : 0;
        $text = $arguments['text'] ?? '';

        if ($accountKey === '' || $conversationId === 0 || $text === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account", "conversation_id", and "text" are required']],
                'isError' => true,
            ];
        }

        $type = $arguments['type'] ?? 'message';
        $state = $arguments['state'] ?? null;
        $status = $arguments['status'] ?? null;
        $userId = isset($arguments['user_id']) ? (int) $arguments['user_id'] : null;

        try {
            $result = $this->freescoutService->createThread(
                $accountKey,
                $conversationId,
                $text,
                $type,
                $state,
                $status,
                $userId,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    ['success' => true, 'thread' => $result],
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
