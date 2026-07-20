<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbDeleteRatingTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_delete_rating';
    }

    public function getDescription(): string
    {
        return 'Remove your personal TMDb rating from a movie or TV series. Requires session_id on the account.';
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
                'account' => [
                    'type' => 'string',
                    'description' => 'TMDb account key. Optional if only one account is configured.',
                ],
            ],
            'required' => ['tmdb_id', 'media_type'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (!isset($arguments['tmdb_id'], $arguments['media_type'])) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: tmdb_id and media_type are required']],
                    'isError' => true,
                ];
            }

            $result = $this->tmdbService->deleteRating(
                mediaType: (string) $arguments['media_type'],
                tmdbId: (int) $arguments['tmdb_id'],
                accountKey: $arguments['account'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error deleting TMDb rating: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
