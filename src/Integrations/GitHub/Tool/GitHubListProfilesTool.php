<?php

namespace App\Integrations\GitHub\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\GitHub\GitHubService;

class GitHubListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly GitHubService $gitHubService,
    ) {
    }

    public function getName(): string
    {
        return 'github_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured GitHub profiles. Returns profile keys, labels, base URL, and any default login. '
            . 'Use the profile key in other GitHub tools to choose which token to use. '
            . 'If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'github';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->gitHubService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing GitHub profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
