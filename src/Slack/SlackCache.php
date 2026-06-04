<?php

namespace App\Slack;

use Psr\Cache\CacheItemPoolInterface;

class SlackCache
{
    public function __construct(
        private readonly CacheItemPoolInterface $slackCache,
    ) {
    }

    public function getAuthInfo(string $accountKey): ?array
    {
        return $this->getArray($this->authInfoKey($accountKey));
    }

    public function setAuthInfo(string $accountKey, array $authInfo): void
    {
        $this->setWithTtl($this->authInfoKey($accountKey), $authInfo, 60 * 30);
    }

    public function getAuthUserId(string $accountKey): ?string
    {
        $item = $this->slackCache->getItem($this->authUserIdKey($accountKey));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setAuthUserId(string $accountKey, string $userId): void
    {
        if ($userId === '') {
            return;
        }

        $this->setWithTtl($this->authUserIdKey($accountKey), $userId, 60 * 30);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getChannels(string $accountKey, string $types): ?array
    {
        $value = $this->getArray($this->channelsKey($accountKey, $types));

        return is_array($value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $channels
     */
    public function setChannels(string $accountKey, string $types, array $channels): void
    {
        $this->setWithTtl($this->channelsKey($accountKey, $types), $channels, 60 * 5);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDirectory(string $accountKey): ?array
    {
        return $this->getArray($this->directoryKey($accountKey));
    }

    /**
     * @param array<string, mixed> $directory
     */
    public function setDirectory(string $accountKey, array $directory): void
    {
        $this->setWithTtl($this->directoryKey($accountKey), $directory, 60 * 10);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessagesPage(
        string $accountKey,
        string $channelId,
        int $limit,
        ?string $oldest,
        ?string $cursor,
    ): ?array {
        $channelVersion = $this->getChannelVersion($accountKey, $channelId);

        return $this->getArray($this->messagesPageKey($accountKey, $channelId, $limit, $oldest, $cursor, $channelVersion));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setMessagesPage(
        string $accountKey,
        string $channelId,
        int $limit,
        ?string $oldest,
        ?string $cursor,
        array $payload,
    ): void {
        $channelVersion = $this->getChannelVersion($accountKey, $channelId);
        $this->setWithTtl(
            $this->messagesPageKey($accountKey, $channelId, $limit, $oldest, $cursor, $channelVersion),
            $payload,
            30,
        );
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getThreadReplies(string $accountKey, string $channelId, string $threadTs, int $limit): ?array
    {
        $channelVersion = $this->getChannelVersion($accountKey, $channelId);
        $item = $this->slackCache->getItem($this->threadRepliesKey($accountKey, $channelId, $threadTs, $limit, $channelVersion));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_array($value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function setThreadReplies(string $accountKey, string $channelId, string $threadTs, int $limit, array $messages): void
    {
        $channelVersion = $this->getChannelVersion($accountKey, $channelId);
        $this->setWithTtl(
            $this->threadRepliesKey($accountKey, $channelId, $threadTs, $limit, $channelVersion),
            $messages,
            30,
        );
    }

    public function bumpChannelVersion(string $accountKey, string $channelId): void
    {
        $item = $this->slackCache->getItem($this->channelVersionKey($accountKey, $channelId));
        $current = (int) ($item->isHit() ? $item->get() : 1);
        $item->set($current + 1);
        $item->expiresAfter(60 * 60 * 24 * 2);
        $this->slackCache->save($item);
    }

    private function getChannelVersion(string $accountKey, string $channelId): int
    {
        $item = $this->slackCache->getItem($this->channelVersionKey($accountKey, $channelId));
        if (!$item->isHit()) {
            $item->set(1);
            $item->expiresAfter(60 * 60 * 24 * 2);
            $this->slackCache->save($item);

            return 1;
        }

        return max(1, (int) $item->get());
    }

    private function authInfoKey(string $accountKey): string
    {
        return sprintf('slack.auth.info.%s', $accountKey);
    }

    private function authUserIdKey(string $accountKey): string
    {
        return sprintf('slack.auth.user.%s', $accountKey);
    }

    private function channelsKey(string $accountKey, string $types): string
    {
        return sprintf('slack.channels.%s.%s', $accountKey, substr(sha1($types), 0, 16));
    }

    private function directoryKey(string $accountKey): string
    {
        return sprintf('slack.directory.%s', $accountKey);
    }

    private function messagesPageKey(
        string $accountKey,
        string $channelId,
        int $limit,
        ?string $oldest,
        ?string $cursor,
        int $version,
    ): string {
        return sprintf(
            'slack.history.%s.%s.%d.%s.%s.v%d',
            $accountKey,
            $channelId,
            $limit,
            substr(sha1((string) $oldest), 0, 12),
            substr(sha1((string) $cursor), 0, 12),
            $version,
        );
    }

    private function threadRepliesKey(
        string $accountKey,
        string $channelId,
        string $threadTs,
        int $limit,
        int $version,
    ): string {
        return sprintf(
            'slack.thread.%s.%s.%s.%d.v%d',
            $accountKey,
            $channelId,
            substr(sha1($threadTs), 0, 16),
            $limit,
            $version,
        );
    }

    private function channelVersionKey(string $accountKey, string $channelId): string
    {
        return sprintf('slack.ver.%s.%s', $accountKey, $channelId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getArray(string $key): ?array
    {
        $item = $this->slackCache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_array($value) ? $value : null;
    }

    private function setWithTtl(string $key, mixed $value, int $seconds): void
    {
        $item = $this->slackCache->getItem($key);
        $item->set($value);
        $item->expiresAfter($seconds);
        $this->slackCache->save($item);
    }
}
