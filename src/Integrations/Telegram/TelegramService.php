<?php

namespace App\Integrations\Telegram;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramService
{
    private const API_BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly TelegramConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, default_chat_id: string|null, allowed_chat_ids: list<string>|null}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'default_chat_id' => $profile->defaultChatId,
                'allowed_chat_ids' => $profile->allowedChatIds,
            ];
        }

        return $profiles;
    }

    /**
     * Bot identity (getMe). Useful to verify the token works.
     *
     * @return array<string, mixed>
     */
    public function getMe(?string $profileKey): array
    {
        return $this->call($profileKey, 'getMe');
    }

    /**
     * Send a text message (sendMessage).
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        ?string $profileKey,
        ?string $chatId,
        string $text,
        ?string $parseMode = null,
        ?int $replyToMessageId = null,
        bool $disableNotification = false,
        bool $disableWebPagePreview = false,
    ): array {
        $profile = $this->resolveProfile($profileKey);
        $resolvedChatId = $this->resolveChatId($profile, $chatId);
        $this->assertChatAllowed($profile, $resolvedChatId);

        $params = [
            'chat_id' => $resolvedChatId,
            'text' => $text,
        ];

        if ($parseMode !== null && $parseMode !== '') {
            $params['parse_mode'] = $parseMode;
        }
        if ($replyToMessageId !== null) {
            $params['reply_to_message_id'] = $replyToMessageId;
        }
        if ($disableNotification) {
            $params['disable_notification'] = true;
        }
        if ($disableWebPagePreview) {
            $params['disable_web_page_preview'] = true;
        }

        return $this->call($profile->key, 'sendMessage', $params);
    }

    /**
     * Recent updates (getUpdates). Handy to discover chat_ids after messaging the bot.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(
        ?string $profileKey,
        ?int $offset = null,
        int $limit = 100,
        int $timeout = 0,
    ): array {
        $params = [
            'limit' => max(1, min(100, $limit)),
            'timeout' => max(0, min(50, $timeout)),
        ];
        if ($offset !== null) {
            $params['offset'] = $offset;
        }

        $result = $this->call($profileKey, 'getUpdates', $params);

        return is_array($result) ? $result : [];
    }

    /**
     * Chat metadata (getChat).
     *
     * @return array<string, mixed>
     */
    public function getChat(?string $profileKey, ?string $chatId): array
    {
        $profile = $this->resolveProfile($profileKey);
        $resolvedChatId = $this->resolveChatId($profile, $chatId);

        return $this->call($profile->key, 'getChat', [
            'chat_id' => $resolvedChatId,
        ]);
    }

    /**
     * Edit a previously sent text message (editMessageText).
     *
     * @return array<string, mixed>
     */
    public function editMessageText(
        ?string $profileKey,
        ?string $chatId,
        int $messageId,
        string $text,
        ?string $parseMode = null,
        bool $disableWebPagePreview = false,
    ): array {
        $profile = $this->resolveProfile($profileKey);
        $resolvedChatId = $this->resolveChatId($profile, $chatId);
        $this->assertChatAllowed($profile, $resolvedChatId);

        $params = [
            'chat_id' => $resolvedChatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        if ($parseMode !== null && $parseMode !== '') {
            $params['parse_mode'] = $parseMode;
        }
        if ($disableWebPagePreview) {
            $params['disable_web_page_preview'] = true;
        }

        return $this->call($profile->key, 'editMessageText', $params);
    }

    /**
     * Delete a message (deleteMessage).
     *
     * @return array<string, mixed>
     */
    public function deleteMessage(?string $profileKey, ?string $chatId, int $messageId): array
    {
        $profile = $this->resolveProfile($profileKey);
        $resolvedChatId = $this->resolveChatId($profile, $chatId);
        $this->assertChatAllowed($profile, $resolvedChatId);

        return $this->call($profile->key, 'deleteMessage', [
            'chat_id' => $resolvedChatId,
            'message_id' => $messageId,
        ]);
    }

    private function resolveProfile(?string $profileKey): TelegramProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if ($profiles === []) {
            throw new \RuntimeException('No Telegram profiles configured for this server');
        }

        return reset($profiles);
    }

    private function resolveChatId(TelegramProfileConfig $profile, ?string $chatId): string
    {
        if ($chatId !== null && $chatId !== '') {
            return $chatId;
        }

        if ($profile->defaultChatId !== null && $profile->defaultChatId !== '') {
            return $profile->defaultChatId;
        }

        throw new \InvalidArgumentException(sprintf(
            'No chat_id provided and profile "%s" has no default_chat_id. '
            . 'Message the bot, then use telegram_get_updates to discover the chat_id.',
            $profile->key,
        ));
    }

    private function assertChatAllowed(TelegramProfileConfig $profile, string $chatId): void
    {
        if ($profile->allowedChatIds === null) {
            return;
        }

        if ($profile->allowedChatIds === []) {
            throw new \InvalidArgumentException(sprintf(
                'Telegram profile "%s" has an empty allowed_chat_ids list — no send/edit/delete targets are permitted. '
                . 'Add your chat id to allowed_chat_ids, or remove the key to allow any chat.',
                $profile->key,
            ));
        }

        if (!in_array($chatId, $profile->allowedChatIds, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Chat id "%s" is not allowed for Telegram profile "%s". Allowed: %s',
                $chatId,
                $profile->key,
                implode(', ', $profile->allowedChatIds),
            ));
        }
    }

    /**
     * @param array<string, scalar|bool> $params
     *
     * @return array<string, mixed>|list<array<string, mixed>>|bool
     */
    private function call(?string $profileKey, string $method, array $params = []): array|bool
    {
        $profile = $this->resolveProfile($profileKey);

        if ($profile->botToken === '' || str_contains($profile->botToken, 'replace-with')) {
            throw new \RuntimeException(sprintf(
                'Telegram profile "%s" is missing a valid bot_token',
                $profile->key,
            ));
        }

        $url = sprintf('%s/bot%s/%s', self::API_BASE, $profile->botToken, $method);

        $options = [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ($params !== []) {
            $options['json'] = $params;
        }

        $response = $this->httpClient->request('POST', $url, $options);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);
        $data = $content !== '' ? json_decode($content, true) : null;

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf(
                'Telegram API error (HTTP %d): unexpected non-JSON response: %s',
                $statusCode,
                $content,
            ));
        }

        if (($data['ok'] ?? false) !== true) {
            throw new \RuntimeException(sprintf(
                'Telegram API error%s: %s',
                isset($data['error_code']) ? sprintf(' (%s)', $data['error_code']) : '',
                $data['description'] ?? 'Unknown error',
            ));
        }

        return $data['result'] ?? [];
    }
}
