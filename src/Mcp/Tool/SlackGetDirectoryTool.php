<?php

namespace App\Mcp\Tool;

use App\Slack\SlackService;

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
                'account' => [
                    'type' => 'string',
                    'description' => 'Slack account key. Use slack_list_accounts to see available accounts.',
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        if ($accountKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "account" is required']],
                'isError' => true,
            ];
        }

        try {
            $directory = $this->slackService->getDirectory($accountKey);

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
