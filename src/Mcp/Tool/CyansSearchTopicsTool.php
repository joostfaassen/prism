<?php

namespace App\Mcp\Tool;

use App\Cyans\CyansService;

class CyansSearchTopicsTool implements ToolInterface
{
    public function __construct(
        private readonly CyansService $cyansService,
    ) {
    }

    public function getName(): string
    {
        return 'cyans_search_topics';
    }

    public function getDescription(): string
    {
        return 'Search Cyans topics by subject text. Performs client-side filtering on the user\'s topic list (the API has no server-side search). Omit username to use the default configured user.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query to match against topic subjects (case-insensitive)',
                ],
                'username' => [
                    'type' => 'string',
                    'description' => 'Cyans username. Defaults to the configured CYANS_USERNAME.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments): array
    {
        $query = $arguments['query'] ?? '';
        $username = $arguments['username'] ?? $this->cyansService->getDefaultUsername();

        if ($query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "query" is required']],
                'isError' => true,
            ];
        }

        if ($username === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'No username provided and CYANS_USERNAME is not configured']],
                'isError' => true,
            ];
        }

        try {
            $results = $this->cyansService->searchTopics($username, $query);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'username' => $username,
                    'query' => $query,
                    'count' => count($results),
                    'topics' => $results,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching topics: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
