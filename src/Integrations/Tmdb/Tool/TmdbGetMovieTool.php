<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbGetMovieTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_get_movie';
    }

    public function getDescription(): string
    {
        return 'Fetch a TMDb movie by numeric TMDb id. Returns a normalized record with title, synopsis, cast, genres, IMDb id when available, and absolute coverUrl/backdropUrl.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tmdb_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric TMDb movie id',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'TMDb account key (from tmdb_list_accounts). Optional if only one account is configured.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Optional TMDb language override (e.g. en-US, nl-NL). Defaults to the account language.',
                ],
            ],
            'required' => ['tmdb_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (!isset($arguments['tmdb_id']) || $arguments['tmdb_id'] === '') {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: tmdb_id is required']],
                    'isError' => true,
                ];
            }

            $record = $this->tmdbService->getMovie(
                tmdbId: (int) $arguments['tmdb_id'],
                accountKey: $arguments['account'] ?? null,
                language: $arguments['language'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $record,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching TMDb movie: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
