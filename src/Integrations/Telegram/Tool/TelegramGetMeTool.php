<?php

namespace App\Integrations\Telegram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Telegram\TelegramService;

class TelegramGetMeTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramService $telegramService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram_get_me';
    }

    public function getDescription(): string
    {
        return 'Return the Telegram bot identity for a configured account (getMe). '
            . 'Use this to verify the bot_token works and to see the bot username/id.';
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
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'telegram';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->telegramService->getMe(
                accountKey: $arguments['account'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error calling telegram_get_me: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
