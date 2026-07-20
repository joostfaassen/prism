<?php

namespace App\Integrations\Matomo\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Matomo\MatomoService;

class MatomoListSitesTool implements ToolInterface
{
    public function __construct(
        private readonly MatomoService $matomoService,
    ) {
    }

    public function getName(): string
    {
        return 'matomo_list_sites';
    }

    public function getDescription(): string
    {
        return 'List the websites (sites) available in a Matomo instance that the configured token can view. Returns each site id (idsite), name, main URL, timezone and currency. Use the idsite value when querying stats with the other Matomo tools.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Matomo profile key (from matomo_list_profiles). Optional if only one profile is configured.',
                ],
            ],
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
            $sites = $this->matomoService->listSites($arguments['profile'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($sites),
                    'sites' => $sites,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Matomo sites: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
