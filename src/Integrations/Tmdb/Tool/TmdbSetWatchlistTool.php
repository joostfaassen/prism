<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbSetWatchlistTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_set_watchlist';
    }

    public function getDescription(): string
    {
        return 'Add or remove a movie or TV series on your TMDb watchlist (wishlist / want-to-watch). Requires session_id on the account (see tmdb_create_session).';
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
                'watchlist' => [
                    'type' => 'boolean',
                    'description' => 'true to add, false to remove',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'TMDb account key. Optional if only one account is configured.',
                ],
            ],
            'required' => ['tmdb_id', 'media_type', 'watchlist'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (!isset($arguments['tmdb_id'], $arguments['media_type'], $arguments['watchlist'])) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: tmdb_id, media_type and watchlist are required']],
                    'isError' => true,
                ];
            }

            $result = $this->tmdbService->setWatchlist(
                mediaType: (string) $arguments['media_type'],
                tmdbId: (int) $arguments['tmdb_id'],
                watchlist: (bool) $arguments['watchlist'],
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
                'content' => [['type' => 'text', 'text' => 'Error updating TMDb watchlist: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
