<?php

namespace App\Integrations\Telegram;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class TelegramConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, TelegramProfileConfig>
     */
    public function getProfiles(): array
    {
        $raw = $this->configLoader->getProfilesByTypeForServer('telegram', $this->serverContext);
        $profiles = [];

        foreach ($raw as $key => $cfg) {
            $defaultChatId = $cfg['default_chat_id'] ?? null;
            if ($defaultChatId !== null) {
                $defaultChatId = (string) $defaultChatId;
                if ($defaultChatId === '') {
                    $defaultChatId = null;
                }
            }

            $profiles[$key] = new TelegramProfileConfig(
                key: $key,
                label: $cfg['label'] ?? $key,
                botToken: $cfg['bot_token'] ?? '',
                defaultChatId: $defaultChatId,
                allowedChatIds: $this->parseAllowedChatIds($cfg['allowed_chat_ids'] ?? null),
            );
        }

        return $profiles;
    }

    public function getProfile(string $key): TelegramProfileConfig
    {
        $profiles = $this->getProfiles();

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Telegram profile: "%s". Available: %s',
                $key,
                implode(', ', array_keys($profiles)),
            ));
        }

        return $profiles[$key];
    }

    /**
     * @return list<string>|null
     */
    private function parseAllowedChatIds(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!is_array($raw)) {
            throw new \InvalidArgumentException(
                'Telegram allowed_chat_ids must be a YAML list of chat ids, e.g. ["123456789"]',
            );
        }

        $ids = [];
        foreach ($raw as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            if (!is_scalar($id)) {
                throw new \InvalidArgumentException(
                    'Telegram allowed_chat_ids entries must be scalar chat ids',
                );
            }
            $ids[] = (string) $id;
        }

        return array_values(array_unique($ids));
    }
}
