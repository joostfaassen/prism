<?php

namespace App\Slack;

use JoliCode\Slack\Api\Client;
use JoliCode\Slack\ClientFactory;
use JoliCode\Slack\Exception\SlackErrorResponse;

class SlackService
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, string> */
    private array $userIds = [];

    public function __construct(
        private readonly SlackConfigLoader $configLoader,
        private readonly SlackCache $cache,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listChannels(string $accountKey, string $types = 'public_channel,private_channel,mpim,im', int $limit = 200): array
    {
        $cached = $this->cache->getChannels($accountKey, $types);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->getClient($accountKey);
        $channels = [];
        $cursor = null;

        try {
            do {
                $params = [
                    'types' => $types,
                    'exclude_archived' => true,
                    'limit' => $limit,
                ];
                if ($cursor !== null) {
                    $params['cursor'] = $cursor;
                }

                $response = $client->conversationsList($params);

                if (!$response->getOk()) {
                    throw new \RuntimeException('Slack API error: conversations.list failed');
                }

                foreach ($response->getChannels() ?? [] as $channel) {
                    $channels[] = $this->formatChannel($channel);
                }

                $cursor = $response->getResponseMetadata()?->getNextCursor();
            } while ($cursor !== null && $cursor !== '');
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'conversations.list');
        }

        $this->cache->setChannels($accountKey, $types, $channels);

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function listMessages(string $accountKey, string $channelId, int $limit = 20, ?string $oldest = null, ?string $cursor = null): array
    {
        $cached = $this->cache->getMessagesPage($accountKey, $channelId, $limit, $oldest, $cursor);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->getClient($accountKey);

        $params = [
            'channel' => $channelId,
            'limit' => $limit,
        ];
        if ($oldest !== null) {
            $params['oldest'] = $oldest;
        }
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        try {
            $response = $client->conversationsHistory($params);
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'conversations.history');
        }

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: conversations.history failed');
        }

        $messages = [];
        foreach ($response->getMessages() ?? [] as $message) {
            $messages[] = $this->formatMessage($message);
        }

        $result = [
            'messages' => $messages,
            'has_more' => $response->getHasMore() ?? false,
            'next_cursor' => $response->getResponseMetadata()?->getNextCursor(),
        ];

        $this->cache->setMessagesPage($accountKey, $channelId, $limit, $oldest, $cursor, $result);

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getThreadReplies(string $accountKey, string $channelId, string $threadTs, int $limit = 50): array
    {
        $cached = $this->cache->getThreadReplies($accountKey, $channelId, $threadTs, $limit);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->getClient($accountKey);

        try {
            $response = $client->conversationsReplies([
                'channel' => $channelId,
                'ts' => $threadTs,
                'limit' => $limit,
            ]);
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'conversations.replies');
        }

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: conversations.replies failed');
        }

        $messages = [];
        foreach ($response->getMessages() ?? [] as $message) {
            $messages[] = $this->formatMessage($message);
        }

        $this->cache->setThreadReplies($accountKey, $channelId, $threadTs, $limit, $messages);

        return $messages;
    }

    /**
     * Find messages in a channel that appear to need a response from the authenticated user.
     *
     * @return array<string, mixed>
     */
    public function getUnrespondedMessages(string $accountKey, string $channelId, int $limit = 50, ?string $oldest = null): array
    {
        $client = $this->getClient($accountKey);
        $userId = $this->getAuthUserId($accountKey);

        $params = [
            'channel' => $channelId,
            'limit' => $limit,
        ];
        if ($oldest !== null) {
            $params['oldest'] = $oldest;
        }

        try {
            $response = $client->conversationsHistory($params);
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'conversations.history');
        }

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: conversations.history failed');
        }

        $unresponded = [];
        foreach ($response->getMessages() ?? [] as $message) {
            if ($message->getUser() === $userId) {
                continue;
            }
            if ($message->getSubtype() !== null) {
                continue;
            }

            $threadTs = $message->getThreadTs();
            $needsResponse = true;

            if ($threadTs !== null && $threadTs === $message->getTs()) {
                $replyUsers = $message->getReplyUsers();
                if (is_array($replyUsers) && in_array($userId, $replyUsers, true)) {
                    $needsResponse = false;
                }
            }

            $text = $message->getText() ?? '';
            $mentionsMe = str_contains($text, "<@{$userId}>");

            if ($needsResponse) {
                $formatted = $this->formatMessage($message);
                $formatted['mentions_me'] = $mentionsMe;
                $unresponded[] = $formatted;
            }
        }

        return [
            'user_id' => $userId,
            'channel_id' => $channelId,
            'count' => count($unresponded),
            'messages' => $unresponded,
        ];
    }

    public function postMessage(string $accountKey, string $channelId, string $text, ?string $threadTs = null): array
    {
        $client = $this->getClient($accountKey);

        $params = [
            'channel' => $channelId,
            'text' => $text,
        ];
        if ($threadTs !== null) {
            $params['thread_ts'] = $threadTs;
        }

        try {
            $response = $client->chatPostMessage($params);
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'chat.postMessage');
        }

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: chat.postMessage failed');
        }

        $this->cache->bumpChannelVersion($accountKey, $channelId);

        return [
            'ok' => true,
            'channel' => $response->getChannel(),
            'ts' => $response->getTs(),
        ];
    }

    public function addReaction(string $accountKey, string $channelId, string $timestamp, string $reaction): array
    {
        $client = $this->getClient($accountKey);

        try {
            $response = $client->reactionsAdd([
                'channel' => $channelId,
                'timestamp' => $timestamp,
                'name' => $reaction,
            ]);
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'reactions.add');
        }

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: reactions.add failed');
        }

        $this->cache->bumpChannelVersion($accountKey, $channelId);

        return [
            'ok' => true,
            'reaction' => $reaction,
            'channel' => $channelId,
            'timestamp' => $timestamp,
        ];
    }

    public function getAuthUserId(string $accountKey): string
    {
        if (isset($this->userIds[$accountKey])) {
            return $this->userIds[$accountKey];
        }

        $cached = $this->cache->getAuthUserId($accountKey);
        if ($cached !== null) {
            $this->userIds[$accountKey] = $cached;

            return $cached;
        }

        $client = $this->getClient($accountKey);

        try {
            $response = $client->authTest();
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'auth.test');
        }

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: auth.test failed');
        }

        $this->userIds[$accountKey] = $response->getUserId() ?? '';
        $this->cache->setAuthUserId($accountKey, $this->userIds[$accountKey]);

        return $this->userIds[$accountKey];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthInfo(string $accountKey): array
    {
        $cached = $this->cache->getAuthInfo($accountKey);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->getClient($accountKey);

        try {
            $response = $client->authTest();
        } catch (SlackErrorResponse $e) {
            $this->handleSlackError($e, 'auth.test');
        }

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: auth.test failed');
        }

        $authInfo = [
            'user' => $response->getUser(),
            'user_id' => $response->getUserId(),
            'team' => $response->getTeam(),
            'team_id' => $response->getTeamId(),
            'url' => $response->getUrl(),
        ];

        $this->cache->setAuthInfo($accountKey, $authInfo);

        return $authInfo;
    }

    /**
     * Build a complete directory of users, channels, and groups for a workspace.
     * Useful for resolving IDs to human-readable names.
     *
     * @return array<string, mixed>
     */
    public function getDirectory(string $accountKey): array
    {
        $cached = $this->cache->getDirectory($accountKey);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->getClient($accountKey);
        $authInfo = $this->getAuthInfo($accountKey);

        $users = [];
        $cursor = null;
        do {
            $params = ['limit' => 200];
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            try {
                $response = $client->usersList($params);
            } catch (SlackErrorResponse $e) {
                $this->handleSlackError($e, 'users.list');
            }

            if (!$response->getOk()) {
                throw new \RuntimeException('Slack API error: users.list failed');
            }
            foreach ($response->getMembers() ?? [] as $user) {
                if ($user->getDeleted() || $user->getId() === 'USLACKBOT') {
                    continue;
                }
                $entry = [
                    'id' => $user->getId(),
                    'name' => $user->getName(),
                    'real_name' => $user->getRealName(),
                    'is_bot' => $user->getIsBot(),
                ];
                $profile = $user->getProfile();
                if ($profile !== null) {
                    $entry['display_name'] = $profile->getDisplayName();
                    $entry['title'] = $profile->getTitle();
                }
                $users[] = $entry;
            }
            $cursor = $response->getResponseMetadata()?->getNextCursor();
        } while ($cursor !== null && $cursor !== '');

        $channels = $this->listChannels($accountKey);

        $userMap = [];
        foreach ($users as $u) {
            $userMap[$u['id']] = $u['display_name'] ?? $u['real_name'] ?? $u['name'];
        }

        $publicChannels = [];
        $privateChannels = [];
        $directMessages = [];
        $groupMessages = [];

        foreach ($channels as $ch) {
            $entry = ['id' => $ch['id'], 'name' => $ch['name'] ?? null];

            if ($ch['is_im'] ?? false) {
                $dmUserId = $ch['user'] ?? null;
                $entry['user_id'] = $dmUserId;
                $entry['user_name'] = $dmUserId !== null ? ($userMap[$dmUserId] ?? $dmUserId) : null;
                $directMessages[] = $entry;
            } elseif ($ch['is_mpim'] ?? false) {
                $groupMessages[] = $entry;
            } elseif ($ch['is_private'] ?? false) {
                $privateChannels[] = $entry;
            } else {
                $publicChannels[] = $entry;
            }
        }

        $directory = [
            'workspace' => [
                'team' => $authInfo['team'],
                'team_id' => $authInfo['team_id'],
                'authenticated_user' => $authInfo['user'],
                'authenticated_user_id' => $authInfo['user_id'],
            ],
            'users' => $users,
            'public_channels' => $publicChannels,
            'private_channels' => $privateChannels,
            'direct_messages' => $directMessages,
            'group_messages' => $groupMessages,
        ];

        $this->cache->setDirectory($accountKey, $directory);

        return $directory;
    }

    /**
     * @param list<array{channel: string, thread_ts: string}> $threads
     *
     * @return array<string, mixed>
     */
    public function bulkGetThreads(
        string $accountKey,
        array $threads,
        int $limit = 50,
    ): array {
        $limit = max(1, min($limit, 200));
        $completed = [];
        $failed = [];

        foreach ($threads as $thread) {
            $channelId = trim((string) ($thread['channel'] ?? ''));
            $threadTs = trim((string) ($thread['thread_ts'] ?? ''));

            if ($channelId === '' || $threadTs === '') {
                $failed[] = [
                    'channel' => $channelId,
                    'thread_ts' => $threadTs,
                    'error' => 'Both "channel" and "thread_ts" are required',
                ];
                continue;
            }

            try {
                $completed[] = [
                    'channel' => $channelId,
                    'thread_ts' => $threadTs,
                    'messages' => $this->getThreadReplies($accountKey, $channelId, $threadTs, $limit),
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'channel' => $channelId,
                    'thread_ts' => $threadTs,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
            'count' => count($completed),
            'failed_count' => count($failed),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessagesWithThreads(
        string $accountKey,
        string $channelId,
        int $messageLimit = 20,
        int $threadLimit = 50,
        int $maxThreadExpansions = 10,
        ?string $oldest = null,
        ?string $cursor = null,
    ): array {
        $messageLimit = max(1, min($messageLimit, 200));
        $threadLimit = max(1, min($threadLimit, 200));
        $maxThreadExpansions = max(0, min($maxThreadExpansions, 20));

        $messagesResult = $this->listMessages($accountKey, $channelId, $messageLimit, $oldest, $cursor);
        $messages = $messagesResult['messages'] ?? [];

        $threads = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $threadTs = $message['thread_ts'] ?? null;
            if (!is_string($threadTs) || $threadTs === '') {
                continue;
            }
            if ($threadTs !== ($message['ts'] ?? null)) {
                continue;
            }

            $threads[] = [
                'channel' => $channelId,
                'thread_ts' => $threadTs,
            ];

            if (count($threads) >= $maxThreadExpansions) {
                break;
            }
        }

        $threadResult = $this->bulkGetThreads($accountKey, $threads, $threadLimit);

        return [
            'messages' => $messages,
            'has_more' => (bool) ($messagesResult['has_more'] ?? false),
            'next_cursor' => $messagesResult['next_cursor'] ?? null,
            'threads' => $threadResult,
        ];
    }

    /**
     * @param list<string> $userIds
     * @param list<string> $channelIds
     *
     * @return array<string, mixed>
     */
    public function resolveIds(string $accountKey, array $userIds = [], array $channelIds = []): array
    {
        $directory = $this->getDirectory($accountKey);

        $usersById = [];
        foreach (($directory['users'] ?? []) as $user) {
            if (!is_array($user)) {
                continue;
            }
            $id = (string) ($user['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $usersById[$id] = [
                'id' => $id,
                'name' => $user['name'] ?? null,
                'real_name' => $user['real_name'] ?? null,
                'display_name' => $user['display_name'] ?? null,
                'title' => $user['title'] ?? null,
                'is_bot' => (bool) ($user['is_bot'] ?? false),
            ];
        }

        $channelsById = [];
        foreach (['public_channels', 'private_channels', 'group_messages', 'direct_messages'] as $group) {
            foreach (($directory[$group] ?? []) as $channel) {
                if (!is_array($channel)) {
                    continue;
                }
                $id = (string) ($channel['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $channelsById[$id] = $channel;
            }
        }

        $resolvedUsers = [];
        $missingUsers = [];
        foreach ($userIds as $userId) {
            if (isset($usersById[$userId])) {
                $resolvedUsers[] = $usersById[$userId];
            } else {
                $missingUsers[] = $userId;
            }
        }

        $resolvedChannels = [];
        $missingChannels = [];
        foreach ($channelIds as $channelId) {
            if (isset($channelsById[$channelId])) {
                $resolvedChannels[] = $channelsById[$channelId];
            } else {
                $missingChannels[] = $channelId;
            }
        }

        return [
            'users' => $resolvedUsers,
            'channels' => $resolvedChannels,
            'missing_users' => $missingUsers,
            'missing_channels' => $missingChannels,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
            ];
        }

        return $accounts;
    }

    private function getClient(string $accountKey): Client
    {
        if (isset($this->clients[$accountKey])) {
            return $this->clients[$accountKey];
        }

        $account = $this->configLoader->getAccount($accountKey);

        if ($account->token === '') {
            throw new \RuntimeException(sprintf('Slack account "%s" has no token configured', $accountKey));
        }

        $this->clients[$accountKey] = ClientFactory::create($account->token);

        return $this->clients[$accountKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatChannel(object $channel): array
    {
        $data = [
            'id' => $channel->getId(),
            'name' => $channel->getName(),
            'is_channel' => $channel->getIsChannel(),
            'is_group' => $channel->getIsGroup(),
            'is_im' => $channel->getIsIm(),
            'is_mpim' => $channel->getIsMpim(),
            'is_private' => $channel->getIsPrivate(),
            'is_archived' => $channel->getIsArchived(),
            'is_member' => $channel->getIsMember(),
            'num_members' => $channel->getNumMembers(),
        ];

        $purpose = $channel->getPurpose();
        if ($purpose !== null && method_exists($purpose, 'getValue')) {
            $data['purpose'] = $purpose->getValue();
        }

        $topic = $channel->getTopic();
        if ($topic !== null && method_exists($topic, 'getValue')) {
            $data['topic'] = $topic->getValue();
        }

        if ($channel->getIsIm()) {
            $data['user'] = $channel->getUser();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMessage(object|array $message): array
    {
        $data = [
            'ts' => $this->messageValue($message, 'ts', 'getTs'),
            'user' => $this->messageValue($message, 'user', 'getUser'),
            'text' => $this->messageValue($message, 'text', 'getText'),
            'type' => $this->messageValue($message, 'type', 'getType'),
        ];

        $subtype = $this->messageValue($message, 'subtype', 'getSubtype');
        if ($subtype !== null) {
            $data['subtype'] = $subtype;
        }

        $threadTs = $this->messageValue($message, 'thread_ts', 'getThreadTs');
        if ($threadTs !== null) {
            $data['thread_ts'] = $threadTs;
            $data['reply_count'] = $this->messageValue($message, 'reply_count', 'getReplyCount');
            $data['reply_users_count'] = $this->messageValue($message, 'reply_users_count', 'getReplyUsersCount');
        }

        $reactions = $this->messageValue($message, 'reactions', 'getReactions');
        if (!empty($reactions)) {
            $data['reactions'] = [];
            foreach ($reactions as $reaction) {
                $data['reactions'][] = $this->formatReaction($reaction);
            }
        }

        $botId = $this->messageValue($message, 'bot_id', 'getBotId');
        if ($botId !== null) {
            $data['bot_id'] = $botId;
        }

        $username = $this->messageValue($message, 'username', 'getUsername');
        if ($username !== null) {
            $data['username'] = $username;
        }

        return $data;
    }

    private function messageValue(object|array $message, string $arrayKey, string $method): mixed
    {
        if (is_array($message)) {
            return $message[$arrayKey] ?? null;
        }

        return method_exists($message, $method) ? $message->{$method}() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatReaction(object|array $reaction): array
    {
        if (is_array($reaction)) {
            return [
                'name' => $reaction['name'] ?? null,
                'count' => $reaction['count'] ?? null,
                'users' => $reaction['users'] ?? [],
            ];
        }

        return [
            'name' => $reaction->getName(),
            'count' => $reaction->getCount(),
            'users' => $reaction->getUsers(),
        ];
    }

    /**
     * Re-throw a SlackErrorResponse with scope details included in the message.
     *
     * @throws \RuntimeException always
     */
    private function handleSlackError(SlackErrorResponse $e, string $method): never
    {
        $code = $e->getErrorCode();
        $meta = $e->getResponseMetadata();

        if ($code === 'missing_scope' && is_array($meta)) {
            $needed = $meta['needed'] ?? 'unknown';
            $provided = $meta['provided'] ?? 'unknown';
            throw new \RuntimeException(sprintf(
                'Slack API error in %s: missing_scope — need "%s" (your token has: %s). '
                . 'Add the scope in your Slack App under OAuth & Permissions → User Token Scopes, then reinstall the app.',
                $method,
                $needed,
                $provided,
            ), 0, $e);
        }

        throw new \RuntimeException(sprintf(
            'Slack API error in %s: %s',
            $method,
            $e->getMessage(),
        ), 0, $e);
    }
}
