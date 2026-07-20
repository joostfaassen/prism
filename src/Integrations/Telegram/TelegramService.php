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
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'default_chat_id' => $account->defaultChatId,
                'allowed_chat_ids' => $account->allowedChatIds,
            ];
        }

        return $accounts;
    }

    /**
     * Bot identity (getMe). Useful to verify the token works.
     *
     * @return array<string, mixed>
     */
    public function getMe(?string $accountKey): array
    {
        return $this->call($accountKey, 'getMe');
    }

    /**
     * Send a text message (sendMessage).
     *
     * @return array<string, mixed>
     */
    public function sendMessage(
        ?string $accountKey,
        ?string $chatId,
        string $text,
        ?string $parseMode = null,
        ?int $replyToMessageId = null,
        bool $disableNotification = false,
        bool $disableWebPagePreview = false,
    ): array {
        $account = $this->resolveAccount($accountKey);
        $resolvedChatId = $this->resolveChatId($account, $chatId);
        $this->assertChatAllowed($account, $resolvedChatId);

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

        return $this->call($account->key, 'sendMessage', $params);
    }

    /**
     * Recent updates (getUpdates). Handy to discover chat_ids after messaging the bot.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(
        ?string $accountKey,
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

        $result = $this->call($accountKey, 'getUpdates', $params);

        return is_array($result) ? $result : [];
    }

    /**
     * Chat metadata (getChat).
     *
     * @return array<string, mixed>
     */
    public function getChat(?string $accountKey, ?string $chatId): array
    {
        $account = $this->resolveAccount($accountKey);
        $resolvedChatId = $this->resolveChatId($account, $chatId);

        return $this->call($account->key, 'getChat', [
            'chat_id' => $resolvedChatId,
        ]);
    }

    /**
     * Edit a previously sent text message (editMessageText).
     *
     * @return array<string, mixed>
     */
    public function editMessageText(
        ?string $accountKey,
        ?string $chatId,
        int $messageId,
        string $text,
        ?string $parseMode = null,
        bool $disableWebPagePreview = false,
    ): array {
        $account = $this->resolveAccount($accountKey);
        $resolvedChatId = $this->resolveChatId($account, $chatId);
        $this->assertChatAllowed($account, $resolvedChatId);

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

        return $this->call($account->key, 'editMessageText', $params);
    }

    /**
     * Delete a message (deleteMessage).
     *
     * @return array<string, mixed>
     */
    public function deleteMessage(?string $accountKey, ?string $chatId, int $messageId): array
    {
        $account = $this->resolveAccount($accountKey);
        $resolvedChatId = $this->resolveChatId($account, $chatId);
        $this->assertChatAllowed($account, $resolvedChatId);

        return $this->call($account->key, 'deleteMessage', [
            'chat_id' => $resolvedChatId,
            'message_id' => $messageId,
        ]);
    }

    private function resolveAccount(?string $accountKey): TelegramAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if ($accounts === []) {
            throw new \RuntimeException('No Telegram accounts configured for this server');
        }

        return reset($accounts);
    }

    private function resolveChatId(TelegramAccountConfig $account, ?string $chatId): string
    {
        if ($chatId !== null && $chatId !== '') {
            return $chatId;
        }

        if ($account->defaultChatId !== null && $account->defaultChatId !== '') {
            return $account->defaultChatId;
        }

        throw new \InvalidArgumentException(sprintf(
            'No chat_id provided and account "%s" has no default_chat_id. '
            . 'Message the bot, then use telegram_get_updates to discover the chat_id.',
            $account->key,
        ));
    }

    private function assertChatAllowed(TelegramAccountConfig $account, string $chatId): void
    {
        if ($account->allowedChatIds === null) {
            return;
        }

        if ($account->allowedChatIds === []) {
            throw new \InvalidArgumentException(sprintf(
                'Telegram account "%s" has an empty allowed_chat_ids list — no send/edit/delete targets are permitted. '
                . 'Add your chat id to allowed_chat_ids, or remove the key to allow any chat.',
                $account->key,
            ));
        }

        if (!in_array($chatId, $account->allowedChatIds, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Chat id "%s" is not allowed for Telegram account "%s". Allowed: %s',
                $chatId,
                $account->key,
                implode(', ', $account->allowedChatIds),
            ));
        }
    }

    /**
     * @param array<string, scalar|bool> $params
     *
     * @return array<string, mixed>|list<array<string, mixed>>|bool
     */
    private function call(?string $accountKey, string $method, array $params = []): array|bool
    {
        $account = $this->resolveAccount($accountKey);

        if ($account->botToken === '' || str_contains($account->botToken, 'replace-with')) {
            throw new \RuntimeException(sprintf(
                'Telegram account "%s" is missing a valid bot_token',
                $account->key,
            ));
        }

        $url = sprintf('%s/bot%s/%s', self::API_BASE, $account->botToken, $method);

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
