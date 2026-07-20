<?php

namespace App\Integrations\Telegram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Telegram\TelegramService;

class TelegramGetUpdatesTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramService $telegramService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram_get_updates';
    }

    public function getDescription(): string
    {
        return 'Fetch recent Telegram updates for a bot (getUpdates). Use this to discover chat_ids: '
            . 'ask someone to message the bot (or add it to a group and send a message), then call this tool '
            . 'and read message.chat.id from the results. Note: this uses long-polling and will not work '
            . 'if a webhook is already set on the bot.';
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
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Optional update_id offset. Pass last_update_id + 1 to acknowledge older updates.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max updates to return (1–100, default 100).',
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
            $updates = $this->telegramService->getUpdates(
                profileKey: $arguments['profile'] ?? null,
                offset: isset($arguments['offset']) ? (int) $arguments['offset'] : null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 100,
            );

            $chats = [];
            foreach ($updates as $update) {
                if (!is_array($update)) {
                    continue;
                }
                $message = $update['message'] ?? $update['edited_message'] ?? $update['channel_post'] ?? null;
                if (!is_array($message) || !isset($message['chat']) || !is_array($message['chat'])) {
                    continue;
                }
                $chat = $message['chat'];
                $chatId = isset($chat['id']) ? (string) $chat['id'] : null;
                if ($chatId === null || isset($chats[$chatId])) {
                    continue;
                }
                $chats[$chatId] = [
                    'chat_id' => $chat['id'],
                    'type' => $chat['type'] ?? null,
                    'title' => $chat['title'] ?? null,
                    'username' => $chat['username'] ?? null,
                    'first_name' => $chat['first_name'] ?? null,
                    'last_name' => $chat['last_name'] ?? null,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($updates),
                    'discovered_chats' => array_values($chats),
                    'updates' => $updates,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Telegram updates: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
