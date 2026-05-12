<?php

namespace App\Mcp\Tool;

use App\Slack\SlackService;

class SlackGetThreadRepliesTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_get_thread_replies';
    }

    public function getDescription(): string
    {
        return 'Get all replies in a Slack message thread. Use the thread_ts from a message returned by slack_list_messages.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Slack account key',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Channel ID where the thread exists',
                ],
                'thread_ts' => [
                    'type' => 'string',
                    'description' => 'Thread parent message timestamp (thread_ts field)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max replies to return (default 50)',
                ],
            ],
            'required' => ['account', 'channel', 'thread_ts'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $channelId = $arguments['channel'] ?? '';
        $threadTs = $arguments['thread_ts'] ?? '';

        if ($accountKey === '' || $channelId === '' || $threadTs === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account", "channel", and "thread_ts" are required']],
                'isError' => true,
            ];
        }

        $limit = min((int) ($arguments['limit'] ?? 50), 200);

        try {
            $replies = $this->slackService->getThreadReplies($accountKey, $channelId, $threadTs, $limit);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'channel' => $channelId,
                    'thread_ts' => $threadTs,
                    'count' => count($replies),
                    'messages' => $replies,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching thread: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
