<?php

namespace App\Integrations\Slack;

use Psr\Cache\CacheItemPoolInterface;

class SlackCache
{
    public function __construct(
        private readonly CacheItemPoolInterface $slackCache,
    ) {
    }

    public function getAuthInfo(string $profileKey): ?array
    {
        return $this->getArray($this->authInfoKey($profileKey));
    }

    public function setAuthInfo(string $profileKey, array $authInfo): void
    {
        $this->setWithTtl($this->authInfoKey($profileKey), $authInfo, 60 * 30);
    }

    public function getAuthUserId(string $profileKey): ?string
    {
        $item = $this->slackCache->getItem($this->authUserIdKey($profileKey));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setAuthUserId(string $profileKey, string $userId): void
    {
        if ($userId === '') {
            return;
        }

        $this->setWithTtl($this->authUserIdKey($profileKey), $userId, 60 * 30);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getChannels(string $profileKey, string $types): ?array
    {
        $value = $this->getArray($this->channelsKey($profileKey, $types));

        return is_array($value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $channels
     */
    public function setChannels(string $profileKey, string $types, array $channels): void
    {
        $this->setWithTtl($this->channelsKey($profileKey, $types), $channels, 60 * 5);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDirectory(string $profileKey): ?array
    {
        return $this->getArray($this->directoryKey($profileKey));
    }

    /**
     * @param array<string, mixed> $directory
     */
    public function setDirectory(string $profileKey, array $directory): void
    {
        $this->setWithTtl($this->directoryKey($profileKey), $directory, 60 * 10);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessagesPage(
        string $profileKey,
        string $channelId,
        int $limit,
        ?string $oldest,
        ?string $cursor,
    ): ?array {
        $channelVersion = $this->getChannelVersion($profileKey, $channelId);

        return $this->getArray($this->messagesPageKey($profileKey, $channelId, $limit, $oldest, $cursor, $channelVersion));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setMessagesPage(
        string $profileKey,
        string $channelId,
        int $limit,
        ?string $oldest,
        ?string $cursor,
        array $payload,
    ): void {
        $channelVersion = $this->getChannelVersion($profileKey, $channelId);
        $this->setWithTtl(
            $this->messagesPageKey($profileKey, $channelId, $limit, $oldest, $cursor, $channelVersion),
            $payload,
            30,
        );
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function getThreadReplies(string $profileKey, string $channelId, string $threadTs, int $limit): ?array
    {
        $channelVersion = $this->getChannelVersion($profileKey, $channelId);
        $item = $this->slackCache->getItem($this->threadRepliesKey($profileKey, $channelId, $threadTs, $limit, $channelVersion));
        if (!$item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_array($value) ? $value : null;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function setThreadReplies(string $profileKey, string $channelId, string $threadTs, int $limit, array $messages): void
    {
        $channelVersion = $this->getChannelVersion($profileKey, $channelId);
        $this->setWithTtl(
            $this->threadRepliesKey($profileKey, $channelId, $threadTs, $limit, $channelVersion),
            $messages,
            30,
        );
    }

    public function bumpChannelVersion(string $profileKey, string $channelId): void
    {
        $item = $this->slackCache->getItem($this->channelVersionKey($profileKey, $channelId));
        $current = (int) ($item->isHit() ? $item->get() : 1);
        $item->set($current + 1);
        $item->expiresAfter(60 * 60 * 24 * 2);
        $this->slackCache->save($item);
    }

    private function getChannelVersion(string $profileKey, string $channelId): int
    {
        $item = $this->slackCache->getItem($this->channelVersionKey($profileKey, $channelId));
        if (!$item->isHit()) {
            $item->set(1);
            $item->expiresAfter(60 * 60 * 24 * 2);
            $this->slackCache->save($item);

            return 1;
        }

        return max(1, (int) $item->get());
    }

    private function authInfoKey(string $profileKey): string
    {
        return sprintf('slack.auth.info.%s', $profileKey);
    }

    private function authUserIdKey(string $profileKey): string
    {
        return sprintf('slack.auth.user.%s', $profileKey);
    }

    private function channelsKey(string $profileKey, string $types): string
    {
        return sprintf('slack.channels.%s.%s', $profileKey, substr(sha1($types), 0, 16));
    }

    private function directoryKey(string $profileKey): string
    {
        return sprintf('slack.directory.%s', $profileKey);
    }

    private function messagesPageKey(
        string $profileKey,
        string $channelId,
        int $limit,
        ?string $oldest,
        ?string $cursor,
        int $version,
    ): string {
        return sprintf(
            'slack.history.%s.%s.%d.%s.%s.v%d',
            $profileKey,
            $channelId,
            $limit,
            substr(sha1((string) $oldest), 0, 12),
            substr(sha1((string) $cursor), 0, 12),
            $version,
        );
    }

    private function threadRepliesKey(
        string $profileKey,
        string $channelId,
        string $threadTs,
        int $limit,
        int $version,
    ): string {
        return sprintf(
            'slack.thread.%s.%s.%s.%d.v%d',
            $profileKey,
            $channelId,
            substr(sha1($threadTs), 0, 16),
            $limit,
            $version,
        );
    }

    private function channelVersionKey(string $profileKey, string $channelId): string
    {
        return sprintf('slack.ver.%s.%s', $profileKey, $channelId);
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
