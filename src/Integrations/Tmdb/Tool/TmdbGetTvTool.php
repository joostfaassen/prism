<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbGetTvTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_get_tv';
    }

    public function getDescription(): string
    {
        return 'Fetch a TMDb TV series by numeric TMDb id. Returns a normalized record with title, synopsis, creators, cast, genres, IMDb id when available, and absolute coverUrl/backdropUrl.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tmdb_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric TMDb TV id',
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
            'required' => ['tmdb_id'],
        ];
    }

    public function getProfileType(): ?string
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

            $record = $this->tmdbService->getTv(
                tmdbId: (int) $arguments['tmdb_id'],
                profileKey: $arguments['profile'] ?? null,
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
                'content' => [['type' => 'text', 'text' => 'Error fetching TMDb TV series: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
