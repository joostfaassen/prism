<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusListTargetsTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_list_targets';
    }

    public function getDescription(): string
    {
        return 'List Prometheus scrape targets and their health (up/down), including the last scrape time, '
            . 'last error and discovered labels. Useful to diagnose why metrics are missing. '
            . 'Optionally filter by state (active/dropped).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Prometheus profile key. Optional if only one profile is configured.',
                ],
                'state' => [
                    'type' => 'string',
                    'description' => 'Optional filter: "active" or "dropped". Defaults to all.',
                    'enum' => ['active', 'dropped'],
                ],
            ],
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
            $result = $this->prometheusService->listTargets(
                profileKey: $arguments['profile'] ?? null,
                state: $arguments['state'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'targets' => $result['data'] ?? [],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Prometheus targets: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
