<?php

namespace App\Mcp\Tool;

use App\Slack\SlackService;

class SlackListMessagesTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_list_messages';
    }

    public function getDescription(): string
    {
        return 'List recent messages in a Slack channel, DM, or group conversation. Returns message text, author, timestamps, thread info, and reactions. Use the channel ID from slack_list_channels.';
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
                    'description' => 'Channel ID (e.g. C01ABC123, D01XYZ789)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max messages to return (default 20, max 200)',
                ],
                'oldest' => [
                    'type' => 'string',
                    'description' => 'Only messages after this Unix timestamp (e.g. "1234567890.123456")',
                ],
                'cursor' => [
                    'type' => 'string',
                    'description' => 'Pagination cursor from a previous response',
                ],
            ],
            'required' => ['account', 'channel'],
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

        if ($accountKey === '' || $channelId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account" and "channel" are required']],
                'isError' => true,
            ];
        }

        $limit = min((int) ($arguments['limit'] ?? 20), 200);
        $oldest = $arguments['oldest'] ?? null;
        $cursor = $arguments['cursor'] ?? null;

        try {
            $result = $this->slackService->listMessages($accountKey, $channelId, $limit, $oldest, $cursor);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing messages: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
