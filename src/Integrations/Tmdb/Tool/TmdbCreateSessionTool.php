<?php

namespace App\Integrations\Tmdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Tmdb\TmdbService;

class TmdbCreateSessionTool implements ToolInterface
{
    public function __construct(
        private readonly TmdbService $tmdbService,
    ) {
    }

    public function getName(): string
    {
        return 'tmdb_create_session';
    }

    public function getDescription(): string
    {
        return 'One-time setup: exchange a TMDb username and password for a session_id (and account_id) to paste into the tmdb profile YAML. Required before rating or watchlist tools work. Does not write the config file — copy the returned session_id into prism.*.yaml yourself.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'description' => 'TMDb profile username',
                ],
                'password' => [
                    'type' => 'string',
                    'description' => 'TMDb profile password (used once to create a session; not stored by Prism)',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'TMDb profile key (from tmdb_list_profiles). Optional if only one profile is configured.',
                ],
            ],
            'required' => ['username', 'password'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'tmdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (empty($arguments['username']) || empty($arguments['password'])) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: username and password are required']],
                    'isError' => true,
                ];
            }

            $result = $this->tmdbService->createSession(
                username: (string) $arguments['username'],
                password: (string) $arguments['password'],
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
                'content' => [['type' => 'text', 'text' => 'Error creating TMDb session: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
