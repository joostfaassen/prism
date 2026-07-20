<?php

namespace App\Integrations\Igdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Igdb\IgdbService;

class IgdbListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly IgdbService $igdbService,
    ) {
    }

    public function getName(): string
    {
        return 'igdb_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured IGDB (game database) profiles. Returns profile keys and labels. Use the profile key in other IGDB tools; if only one profile is configured, the profile argument can be omitted.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'igdb';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->igdbService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing IGDB profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
