<?php

namespace App\Mcp\Tool;

use App\Slack\SlackService;

class SlackPostMessageTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_post_message';
    }

    public function getDescription(): string
    {
        return 'Post a message to a Slack channel, DM, or group conversation. The message appears as the authenticated user. Supports replying to threads by providing thread_ts.';
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
                    'description' => 'Channel ID, DM ID, or group conversation ID to post to',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Message text (supports Slack markdown: *bold*, _italic_, `code`, ```code block```, <URL|link text>)',
                ],
                'thread_ts' => [
                    'type' => 'string',
                    'description' => 'Optional thread parent timestamp to reply in a thread instead of posting a new message',
                ],
            ],
            'required' => ['account', 'channel', 'text'],
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
        $text = trim($arguments['text'] ?? '');
        $threadTs = $arguments['thread_ts'] ?? null;

        if ($accountKey === '' || $channelId === '' || $text === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account", "channel", and "text" are required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->slackService->postMessage($accountKey, $channelId, $text, $threadTs);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error posting message: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
