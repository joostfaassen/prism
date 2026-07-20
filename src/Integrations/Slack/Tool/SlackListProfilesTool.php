<?php

namespace App\Integrations\Slack\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Slack\SlackService;

class SlackListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Slack workspace profiles. Returns profile keys and labels. Use the profile key in other Slack tools to specify which workspace to interact with.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->slackService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Slack profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
