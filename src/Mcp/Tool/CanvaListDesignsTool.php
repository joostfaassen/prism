<?php

namespace App\Mcp\Tool;

use App\Canva\CanvaService;

class CanvaListDesignsTool implements ToolInterface
{
    public function __construct(
        private readonly CanvaService $canvaService,
    ) {
    }

    public function getName(): string
    {
        return 'canva_list_designs';
    }

    public function getDescription(): string
    {
        return 'List the Canva designs in the user\'s projects (and designs shared with the user). Returns design metadata such as id, title, URLs, thumbnail, and page count. Supports an optional search query, ownership filter, sorting, and pagination via a continuation token. To page through results, pass the "continuation" value from the previous response.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Canva account key (optional if only one account is configured).',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term to filter designs by title (max 255 characters).',
                ],
                'continuation' => [
                    'type' => 'string',
                    'description' => 'Continuation token from a previous response, used to fetch the next page of designs.',
                ],
                'ownership' => [
                    'type' => 'string',
                    'enum' => ['any', 'owned', 'shared'],
                    'description' => 'Filter by the user\'s ownership of the designs. Defaults to "any".',
                ],
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ['relevance', 'modified_descending', 'modified_ascending', 'title_descending', 'title_ascending'],
                    'description' => 'Sort order for the returned designs.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of designs to return (1-100, default 25).',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'canva';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->canvaService->listDesigns(
                accountKey: $arguments['account'] ?? null,
                query: isset($arguments['query']) ? (string) $arguments['query'] : null,
                continuation: isset($arguments['continuation']) ? (string) $arguments['continuation'] : null,
                ownership: isset($arguments['ownership']) ? (string) $arguments['ownership'] : null,
                sortBy: isset($arguments['sort_by']) ? (string) $arguments['sort_by'] : null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Canva designs: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
