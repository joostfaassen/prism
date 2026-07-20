<?php

namespace App\Integrations\Canva\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Canva\CanvaService;

class CanvaListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly CanvaService $canvaService,
    ) {
    }

    public function getName(): string
    {
        return 'canva_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Canva profiles. Returns each profile key, label, whether it is connected (has a valid OAuth token), and the granted scopes. Use the profile key in other Canva tools to choose which profile to query. If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'canva';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->canvaService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Canva profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
