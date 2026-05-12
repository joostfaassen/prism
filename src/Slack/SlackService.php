<?php

namespace App\Slack;

use JoliCode\Slack\Api\Client;
use JoliCode\Slack\ClientFactory;

class SlackService
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, string> */
    private array $userIds = [];

    public function __construct(
        private readonly SlackConfigLoader $configLoader,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listChannels(string $accountKey, string $types = 'public_channel,private_channel,mpim,im', int $limit = 200): array
    {
        $client = $this->getClient($accountKey);
        $channels = [];
        $cursor = null;

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

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function listMessages(string $accountKey, string $channelId, int $limit = 20, ?string $oldest = null, ?string $cursor = null): array
    {
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

        $response = $client->conversationsHistory($params);

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: conversations.history failed');
        }

        $messages = [];
        foreach ($response->getMessages() ?? [] as $message) {
            $messages[] = $this->formatMessage($message);
        }

        return [
            'messages' => $messages,
            'has_more' => $response->getHasMore() ?? false,
            'next_cursor' => $response->getResponseMetadata()?->getNextCursor(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getThreadReplies(string $accountKey, string $channelId, string $threadTs, int $limit = 50): array
    {
        $client = $this->getClient($accountKey);

        $response = $client->conversationsReplies([
            'channel' => $channelId,
            'ts' => $threadTs,
            'limit' => $limit,
        ]);

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: conversations.replies failed');
        }

        $messages = [];
        foreach ($response->getMessages() ?? [] as $message) {
            $messages[] = $this->formatMessage($message);
        }

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

        $response = $client->conversationsHistory($params);

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

        $response = $client->chatPostMessage($params);

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: chat.postMessage failed');
        }

        return [
            'ok' => true,
            'channel' => $response->getChannel(),
            'ts' => $response->getTs(),
        ];
    }

    public function addReaction(string $accountKey, string $channelId, string $timestamp, string $reaction): array
    {
        $client = $this->getClient($accountKey);

        $response = $client->reactionsAdd([
            'channel' => $channelId,
            'timestamp' => $timestamp,
            'name' => $reaction,
        ]);

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: reactions.add failed');
        }

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

        $client = $this->getClient($accountKey);
        $response = $client->authTest();

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: auth.test failed');
        }

        $this->userIds[$accountKey] = $response->getUserId() ?? '';

        return $this->userIds[$accountKey];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthInfo(string $accountKey): array
    {
        $client = $this->getClient($accountKey);
        $response = $client->authTest();

        if (!$response->getOk()) {
            throw new \RuntimeException('Slack API error: auth.test failed');
        }

        return [
            'user' => $response->getUser(),
            'user_id' => $response->getUserId(),
            'team' => $response->getTeam(),
            'team_id' => $response->getTeamId(),
            'url' => $response->getUrl(),
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
    private function formatMessage(object $message): array
    {
        $data = [
            'ts' => $message->getTs(),
            'user' => $message->getUser(),
            'text' => $message->getText(),
            'type' => $message->getType(),
        ];

        if ($message->getSubtype() !== null) {
            $data['subtype'] = $message->getSubtype();
        }

        if ($message->getThreadTs() !== null) {
            $data['thread_ts'] = $message->getThreadTs();
            $data['reply_count'] = $message->getReplyCount();
            $data['reply_users_count'] = $message->getReplyUsersCount();
        }

        $reactions = $message->getReactions();
        if (!empty($reactions)) {
            $data['reactions'] = [];
            foreach ($reactions as $reaction) {
                $data['reactions'][] = [
                    'name' => $reaction->getName(),
                    'count' => $reaction->getCount(),
                    'users' => $reaction->getUsers(),
                ];
            }
        }

        if ($message->getBotId() !== null) {
            $data['bot_id'] = $message->getBotId();
        }

        $username = $message->getUsername();
        if ($username !== null) {
            $data['username'] = $username;
        }

        return $data;
    }
}
