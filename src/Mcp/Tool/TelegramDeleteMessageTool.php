<?php

namespace App\Mcp\Tool;

use App\Telegram\TelegramService;

class TelegramDeleteMessageTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramService $telegramService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram_delete_message';
    }

    public function getDescription(): string
    {
        return 'Delete a Telegram message previously sent by the bot (deleteMessage). '
            . 'Requires chat_id (or default_chat_id) and message_id.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Telegram account key. Optional if only one account is configured.',
                ],
                'chat_id' => [
                    'type' => 'string',
                    'description' => 'Chat id containing the message. Optional if the account has default_chat_id.',
                ],
                'message_id' => [
                    'type' => 'integer',
                    'description' => 'Id of the message to delete.',
                ],
            ],
            'required' => ['message_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'telegram';
    }

    public function execute(array $arguments): array
    {
        $messageId = isset($arguments['message_id']) ? (int) $arguments['message_id'] : 0;

        if ($messageId <= 0) {
            return [
                'content' => [['type' => 'text', 'text' => 'The "message_id" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->telegramService->deleteMessage(
                accountKey: $arguments['account'] ?? null,
                chatId: isset($arguments['chat_id']) ? (string) $arguments['chat_id'] : null,
                messageId: $messageId,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'deleted' => $result === true || $result === [],
                    'result' => $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error deleting Telegram message: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
