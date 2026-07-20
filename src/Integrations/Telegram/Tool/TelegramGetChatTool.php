<?php

namespace App\Integrations\Telegram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Telegram\TelegramService;

class TelegramGetChatTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramService $telegramService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram_get_chat';
    }

    public function getDescription(): string
    {
        return 'Get metadata for a Telegram chat (getChat): title, type, username, description, etc. '
            . 'The bot must already be a member of the chat (or it must be a private chat with the bot).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Telegram profile key. Optional if only one profile is configured.',
                ],
                'chat_id' => [
                    'type' => 'string',
                    'description' => 'Chat id to look up. Optional if the profile has default_chat_id.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'telegram';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->telegramService->getChat(
                profileKey: $arguments['profile'] ?? null,
                chatId: isset($arguments['chat_id']) ? (string) $arguments['chat_id'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting Telegram chat: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
