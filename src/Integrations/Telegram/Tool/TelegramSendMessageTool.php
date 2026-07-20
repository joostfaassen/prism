<?php

namespace App\Integrations\Telegram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Telegram\TelegramService;

class TelegramSendMessageTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramService $telegramService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram_send_message';
    }

    public function getDescription(): string
    {
        return 'Send a text message via a Telegram bot. Requires chat_id (or a default_chat_id on the account). '
            . 'If the account has allowed_chat_ids configured, only those chat ids are accepted. '
            . 'To discover chat_ids: have someone message the bot, then call telegram_get_updates. '
            . 'Supports optional parse_mode (MarkdownV2, HTML, Markdown), reply_to_message_id, and silent send.';
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
                    'description' => 'Target chat id (user, group, or channel). Optional if the account has default_chat_id.',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Message text to send (max 4096 characters).',
                ],
                'parse_mode' => [
                    'type' => 'string',
                    'description' => 'Optional formatting: "MarkdownV2", "HTML", or "Markdown".',
                    'enum' => ['MarkdownV2', 'HTML', 'Markdown'],
                ],
                'reply_to_message_id' => [
                    'type' => 'integer',
                    'description' => 'Optional message id to reply to.',
                ],
                'disable_notification' => [
                    'type' => 'boolean',
                    'description' => 'If true, send silently (no notification sound).',
                ],
                'disable_web_page_preview' => [
                    'type' => 'boolean',
                    'description' => 'If true, disable link previews.',
                ],
            ],
            'required' => ['text'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'telegram';
    }

    public function execute(array $arguments): array
    {
        $text = trim((string) ($arguments['text'] ?? ''));

        if ($text === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "text" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->telegramService->sendMessage(
                accountKey: $arguments['account'] ?? null,
                chatId: isset($arguments['chat_id']) ? (string) $arguments['chat_id'] : null,
                text: $text,
                parseMode: isset($arguments['parse_mode']) ? (string) $arguments['parse_mode'] : null,
                replyToMessageId: isset($arguments['reply_to_message_id'])
                    ? (int) $arguments['reply_to_message_id']
                    : null,
                disableNotification: (bool) ($arguments['disable_notification'] ?? false),
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
                'content' => [['type' => 'text', 'text' => 'Error sending Telegram message: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
