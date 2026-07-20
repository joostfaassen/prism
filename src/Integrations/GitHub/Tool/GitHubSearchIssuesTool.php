<?php

namespace App\Integrations\GitHub\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\GitHub\GitHubService;

class GitHubSearchIssuesTool implements ToolInterface
{
    public function __construct(
        private readonly GitHubService $gitHubService,
    ) {
    }

    public function getName(): string
    {
        return 'github_search_issues';
    }

    public function getDescription(): string
    {
        return 'Search GitHub issues and pull requests with raw GitHub search qualifiers. '
            . 'Pass the full query in "q", e.g. "is:pr is:open author:joostfaassen", '
            . '"is:pr review-requested:@me", "repo:linkorb/nebula is:issue label:bug". '
            . 'Good for PR triage and lookups. Returns normalized items with repo, number, title, state, timestamps and URL.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'GitHub profile key. Optional if only one profile is configured.',
                ],
                'q' => [
                    'type' => 'string',
                    'description' => 'GitHub search query using issue/PR qualifiers, e.g. "is:pr is:open author:me".',
                ],
                'sort' => [
                    'type' => 'string',
                    'description' => 'Sort field. Defaults to "updated".',
                    'enum' => ['created', 'updated', 'comments'],
                ],
                'order' => [
                    'type' => 'string',
                    'description' => 'Sort order. Defaults to "desc".',
                    'enum' => ['asc', 'desc'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max items to return (1-100). Defaults to 30.',
                ],
            ],
            'required' => ['q'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'github';
    }

    public function execute(array $arguments): array
    {
        $query = trim((string) ($arguments['q'] ?? ''));
        if ($query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "q" argument is required, e.g. "is:pr is:open author:me".']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->gitHubService->searchIssues(
                profileKey: $arguments['profile'] ?? null,
                query: $query,
                sort: $arguments['sort'] ?? 'updated',
                order: $arguments['order'] ?? 'desc',
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 30,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching GitHub issues: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
