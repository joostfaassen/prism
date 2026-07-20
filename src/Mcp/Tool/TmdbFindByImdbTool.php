<?php

namespace App\Mcp\Tool;

use App\Tmdb\TmdbService;

class TmdbFindByImdbTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_find_by_imdb';
    }

    public function getDescription(): string
    {
        return 'Resolve a film or TV series from an IMDb id (tt…) via TMDb. Detects movie vs TV automatically. Returns a normalized record with title, synopsis, cast, genres, external ids, and absolute coverUrl/backdropUrl ready to download.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'imdb_id' => [
                    'type' => 'string',
                    'description' => 'IMDb title id, e.g. tt2798920',
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
            'required' => ['imdb_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (empty($arguments['imdb_id'])) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: imdb_id is required']],
                    'isError' => true,
                ];
            }

            $record = $this->tmdbService->findByImdb(
                imdbId: (string) $arguments['imdb_id'],
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
                'content' => [['type' => 'text', 'text' => 'Error finding TMDb title by IMDb id: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
