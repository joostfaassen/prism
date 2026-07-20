<?php

namespace App\Integrations\Telegram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Telegram\TelegramService;

class TelegramListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly TelegramService $telegramService,
    ) {
    }

    public function getName(): string
    {
        return 'telegram_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Telegram bot accounts. Returns account keys, labels, and any default chat_id. '
            . 'Use the account key in other Telegram tools. If only one account is configured, account can be omitted elsewhere.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
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
            $accounts = $this->telegramService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Telegram accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
