<?php

namespace App\Integrations\Loki\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Loki\LokiService;

class LokiListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly LokiService $lokiService,
    ) {
    }

    public function getName(): string
    {
        return 'loki_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Loki (log aggregation) profiles. Returns profile keys, labels, and base '
            . 'URLs. Use the profile key in other Loki tools to choose which instance to query. '
            . 'If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'loki';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->lokiService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Loki profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
