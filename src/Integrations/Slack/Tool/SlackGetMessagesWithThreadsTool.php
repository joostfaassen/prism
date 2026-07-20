<?php

namespace App\Integrations\Slack\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Slack\SlackService;

class SlackGetMessagesWithThreadsTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_get_messages_with_threads';
    }

    public function getDescription(): string
    {
        return 'Fetch channel messages and expand top thread roots in the same call to reduce MCP roundtrips for agent workflows.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Slack profile key',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Channel ID (e.g. C01ABC123)',
                ],
                'message_limit' => [
                    'type' => 'integer',
                    'description' => 'Max messages to return (default 20, max 200)',
                ],
                'thread_limit' => [
                    'type' => 'integer',
                    'description' => 'Max messages per expanded thread (default 50, max 200)',
                ],
                'max_thread_expansions' => [
                    'type' => 'integer',
                    'description' => 'Max root threads to expand (default 10, max 20)',
                ],
                'oldest' => [
                    'type' => 'string',
                    'description' => 'Only messages after this Unix timestamp',
                ],
                'cursor' => [
                    'type' => 'string',
                    'description' => 'Pagination cursor from previous call',
                ],
            ],
            'required' => ['profile', 'channel'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        $profileKey = trim((string) ($arguments['profile'] ?? ''));
        $channelId = trim((string) ($arguments['channel'] ?? ''));
        if ($profileKey === '' || $channelId === '') {
            return $this->error('Parameters "profile" and "channel" are required');
        }

        $messageLimit = max(1, min((int) ($arguments['message_limit'] ?? 20), 200));
        $threadLimit = max(1, min((int) ($arguments['thread_limit'] ?? 50), 200));
        $maxThreadExpansions = max(0, min((int) ($arguments['max_thread_expansions'] ?? 10), 20));
        $oldest = isset($arguments['oldest']) ? (string) $arguments['oldest'] : null;
        $cursor = isset($arguments['cursor']) ? (string) $arguments['cursor'] : null;

        try {
            $result = $this->slackService->getMessagesWithThreads(
                profileKey: $profileKey,
                channelId: $channelId,
                messageLimit: $messageLimit,
                threadLimit: $threadLimit,
                maxThreadExpansions: $maxThreadExpansions,
                oldest: $oldest,
                cursor: $cursor,
            );

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error fetching messages with threads: ' . $e->getMessage());
        }
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: true}
     */
    private function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
