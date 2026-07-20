<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbSearchTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_search';
    }

    public function getDescription(): string
    {
        return 'Search TMDb for a movie or TV series by title. Returns the top hit as a normalized record (with coverUrl) plus a short list of light results. Prefer tmdb_find_by_imdb when you already have an IMDb id.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Title to search for',
                ],
                'year' => [
                    'type' => 'integer',
                    'description' => 'Optional release year filter (movie) or first-air-date year (tv)',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['movie', 'tv'],
                    'description' => 'Search movies or TV. Default: movie',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'TMDb profile key (from tmdb_list_profiles). Optional if only one profile is configured.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Optional TMDb language override (e.g. en-US, nl-NL). Defaults to the profile language.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'tmdb';
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

            $year = isset($arguments['year']) ? (int) $arguments['year'] : null;
            $payload = $this->tmdbService->search(
                query: (string) $arguments['query'],
                year: $year,
                kind: (string) ($arguments['kind'] ?? 'movie'),
                profileKey: $arguments['profile'] ?? null,
                language: $arguments['language'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching TMDb: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
