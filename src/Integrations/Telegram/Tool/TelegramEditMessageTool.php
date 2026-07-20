<?php

namespace App\Integrations\Telegram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Telegram\TelegramService;

class TelegramEditMessageTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramService $telegramService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram_edit_message';
    }

    public function getDescription(): string
    {
        return 'Edit the text of a previously sent Telegram bot message (editMessageText). '
            . 'Requires chat_id (or default_chat_id) and message_id from a prior send.';
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
                    'description' => 'Chat id containing the message. Optional if the profile has default_chat_id.',
                ],
                'message_id' => [
                    'type' => 'integer',
                    'description' => 'Id of the message to edit (from telegram_send_message result).',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'New message text (max 4096 characters).',
                ],
                'parse_mode' => [
                    'type' => 'string',
                    'description' => 'Optional formatting: "MarkdownV2", "HTML", or "Markdown".',
                    'enum' => ['MarkdownV2', 'HTML', 'Markdown'],
                ],
                'disable_web_page_preview' => [
                    'type' => 'boolean',
                    'description' => 'If true, disable link previews.',
                ],
            ],
            'required' => ['message_id', 'text'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'telegram';
    }

    public function execute(array $arguments): array
    {
        $text = trim((string) ($arguments['text'] ?? ''));
        $messageId = isset($arguments['message_id']) ? (int) $arguments['message_id'] : 0;

        if ($text === '' || $messageId <= 0) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "message_id" and "text" are required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->telegramService->editMessageText(
                profileKey: $arguments['profile'] ?? null,
                chatId: isset($arguments['chat_id']) ? (string) $arguments['chat_id'] : null,
                messageId: $messageId,
                text: $text,
                parseMode: isset($arguments['parse_mode']) ? (string) $arguments['parse_mode'] : null,
                disableWebPagePreview: (bool) ($arguments['disable_web_page_preview'] ?? false),
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error editing Telegram message: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
