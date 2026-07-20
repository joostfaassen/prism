<?php

namespace App\Integrations\Telegram;

class TelegramProfileConfig
{
    /**
     * @param list<string>|null $allowedChatIds null = no restriction; non-empty list = allowlist for write targets
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $botToken,
        public readonly ?string $defaultChatId = null,
        public readonly ?array $allowedChatIds = null,
    ) {
    }
}
