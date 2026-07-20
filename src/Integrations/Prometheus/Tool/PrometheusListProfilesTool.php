<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Prometheus profiles. Returns profile keys, labels, and base URLs. '
            . 'Use the profile key in other Prometheus tools to choose which instance to query. '
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
        return 'prometheus';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->prometheusService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Prometheus profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
