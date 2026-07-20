<?php

namespace App\Integrations\Slack\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Slack\SlackService;

class SlackGetDirectoryTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_get_directory';
    }

    public function getDescription(): string
    {
        return 'Get a complete directory of a Slack workspace: all users (ID → name mapping), public channels, private channels, DMs, and group conversations. Useful for resolving user/channel IDs to human-readable names.';
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

        try {
            $directory = $this->slackService->getDirectory($profileKey);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $directory,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching directory: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
