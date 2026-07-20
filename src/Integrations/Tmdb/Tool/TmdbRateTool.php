<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbRateTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_rate';
    }

    public function getDescription(): string
    {
        return 'Rate a TMDb movie or TV series (0.5–10 in half-star steps) on your TMDb profile. Requires session_id on the profile (see tmdb_create_session).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tmdb_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric TMDb movie or TV id',
                ],
                'media_type' => [
                    'type' => 'string',
                    'enum' => ['movie', 'tv'],
                    'description' => 'movie or tv',
                ],
                'rating' => [
                    'type' => 'number',
                    'description' => 'Personal rating from 0.5 to 10 in 0.5 steps (e.g. 7.5)',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'TMDb profile key. Optional if only one profile is configured.',
                ],
            ],
            'required' => ['tmdb_id', 'media_type', 'rating'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (!isset($arguments['tmdb_id'], $arguments['media_type'], $arguments['rating'])) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: tmdb_id, media_type and rating are required']],
                    'isError' => true,
                ];
            }

            $result = $this->tmdbService->rate(
                mediaType: (string) $arguments['media_type'],
                tmdbId: (int) $arguments['tmdb_id'],
                value: (float) $arguments['rating'],
                profileKey: $arguments['profile'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error rating on TMDb: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
