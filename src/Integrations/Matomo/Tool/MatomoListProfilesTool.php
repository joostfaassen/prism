<?php

namespace App\Integrations\Matomo\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Matomo\MatomoService;

class MatomoListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly MatomoService $matomoService,
    ) {
    }

    public function getName(): string
    {
        return 'matomo_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Matomo analytics profiles. Returns profile keys, labels, and any default site. Use the profile key in other Matomo tools to choose which instance to query. If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'matomo';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->matomoService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Matomo profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
