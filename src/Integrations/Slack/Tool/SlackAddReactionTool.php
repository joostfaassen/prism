<?php

namespace App\Integrations\Slack\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Slack\SlackService;

class SlackAddReactionTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_add_reaction';
    }

    public function getDescription(): string
    {
        return 'Add an emoji reaction to a Slack message. The reaction appears as if sent by the authenticated user. Common reactions: thumbsup, eyes, white_check_mark, raised_hands, heart, tada, thinking_face.';
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
                    'description' => 'Channel ID where the message is',
                ],
                'timestamp' => [
                    'type' => 'string',
                    'description' => 'Message timestamp (ts field) to react to',
                ],
                'reaction' => [
                    'type' => 'string',
                    'description' => 'Emoji name without colons (e.g. "thumbsup", "eyes", "white_check_mark")',
                ],
            ],
            'required' => ['profile', 'channel', 'timestamp', 'reaction'],
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
        $timestamp = $arguments['timestamp'] ?? '';
        $reaction = trim($arguments['reaction'] ?? '', ': ');

        if ($profileKey === '' || $channelId === '' || $timestamp === '' || $reaction === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "profile", "channel", "timestamp", and "reaction" are all required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->slackService->addReaction($profileKey, $channelId, $timestamp, $reaction);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error adding reaction: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
