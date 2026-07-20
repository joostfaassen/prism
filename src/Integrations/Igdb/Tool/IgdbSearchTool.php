<?php

namespace App\Integrations\Igdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Igdb\IgdbService;

class IgdbSearchTool implements ToolInterface
{
    public function __construct(
        private readonly IgdbService $igdbService,
    ) {
    }

    public function getName(): string
    {
        return 'igdb_search';
    }

    public function getDescription(): string
    {
        return 'Search IGDB for a game by name. Returns the top hit as a normalized record (with coverUrl and platforms) plus a short list of light results. Prefer igdb_find when you already have an IGDB id or slug.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Game title to search for',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'IGDB profile key (from igdb_list_profiles). Optional if only one profile is configured.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'igdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (empty($arguments['query'])) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: query is required']],
                    'isError' => true,
                ];
            }

            $payload = $this->igdbService->search(
                query: (string) $arguments['query'],
                profileKey: $arguments['profile'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching IGDB: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
