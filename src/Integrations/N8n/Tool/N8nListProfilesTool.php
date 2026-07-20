<?php

namespace App\Integrations\N8n\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\N8n\N8nService;

class N8nListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly N8nService $n8nService,
    ) {
    }

    public function getName(): string
    {
        return 'n8n_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured n8n automation profiles (instances). Returns profile keys, labels and base URLs. Use the profile key in other n8n tools to choose which instance to query. If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'n8n';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->n8nService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing n8n profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
