<?php

namespace App\Integrations\Slack\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Slack\SlackService;

class SlackListChannelsTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_list_channels';
    }

    public function getDescription(): string
    {
        return 'List Slack channels, DMs, and group conversations. Supports filtering by type: public_channel, private_channel, mpim (group DMs), im (1:1 DMs). Returns channel IDs, names, and metadata.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Slack profile key. Use slack_list_profiles to see available profiles.',
                ],
                'types' => [
                    'type' => 'string',
                    'description' => 'Comma-separated channel types to include. Options: public_channel, private_channel, mpim, im. Defaults to all types.',
                ],
            ],
            'required' => ['profile'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        if ($profileKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "profile" is required']],
                'isError' => true,
            ];
        }

        $types = $arguments['types'] ?? 'public_channel,private_channel,mpim,im';

        try {
            $channels = $this->slackService->listChannels($profileKey, $types);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($channels),
                    'channels' => $channels,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing channels: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
