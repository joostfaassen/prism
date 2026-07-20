<?php

namespace App\Integrations\Telegram;

use App\Integrations\IntegrationInterface;

class TelegramIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'telegram';
    }

    public function getLabel(): string
    {
        return 'Telegram';
    }

    public function getDescription(): string
    {
        return 'Telegram Bot API — send and edit messages, inspect chats and updates.';
    }
}
