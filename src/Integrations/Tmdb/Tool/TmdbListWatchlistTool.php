<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbListWatchlistTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_list_watchlist';
    }

    public function getDescription(): string
    {
        return 'List movies or TV series on your TMDb watchlist (wishlist). Paginated. Requires session_id on the profile.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'media_type' => [
                    'type' => 'string',
                    'enum' => ['movie', 'tv'],
                    'description' => 'List watchlist movies or TV. Default: movie',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number (default 1)',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'TMDb profile key. Optional if only one profile is configured.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->tmdbService->listWatchlist(
                mediaType: (string) ($arguments['media_type'] ?? 'movie'),
                page: isset($arguments['page']) ? (int) $arguments['page'] : 1,
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
                'content' => [['type' => 'text', 'text' => 'Error listing TMDb watchlist: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
