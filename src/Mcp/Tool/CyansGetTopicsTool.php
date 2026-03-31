<?php

namespace App\Mcp\Tool;

use App\Cyans\CyansService;

class CyansGetTopicsTool implements ToolInterface
{
    public function __construct(
        private readonly CyansService $cyansService,
    ) {
    }

    public function getName(): string
    {
        return 'cyans_get_open_topics';
    }

    public function getDescription(): string
    {
        return 'Get open/active Cyans topics for a user. Returns topic summaries sorted by last update. Omit username to use the default configured user.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'description' => 'Cyans username. Defaults to the configured CYANS_USERNAME.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $username = $arguments['username'] ?? $this->cyansService->getDefaultUsername();

        if ($username === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'No username provided and CYANS_USERNAME is not configured']],
                'isError' => true,
            ];
        }

        try {
            $topics = $this->cyansService->getOpenTopics($username);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'username' => $username,
                    'count' => count($topics),
                    'topics' => $topics,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching topics: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
