<?php

namespace App\Integrations\Slack\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Slack\SlackService;

class SlackGetUnrespondedMessagesTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_get_unresponded_messages';
    }

    public function getDescription(): string
    {
        return 'Find messages in a channel or DM that the authenticated user has not responded to. Filters out the user\'s own messages and messages with bot subtypes. Shows whether each message mentions the user directly.';
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
                    'description' => 'Channel ID to check for unresponded messages',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max messages to scan (default 50, max 200)',
                ],
                'oldest' => [
                    'type' => 'string',
                    'description' => 'Only check messages after this Unix timestamp',
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
        $profileKey = $arguments['profile'] ?? '';
        $channelId = $arguments['channel'] ?? '';

        if ($profileKey === '' || $channelId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "profile" and "channel" are required']],
                'isError' => true,
            ];
        }

        $limit = min((int) ($arguments['limit'] ?? 50), 200);
        $oldest = $arguments['oldest'] ?? null;

        try {
            $result = $this->slackService->getUnrespondedMessages($profileKey, $channelId, $limit, $oldest);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error checking messages: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
