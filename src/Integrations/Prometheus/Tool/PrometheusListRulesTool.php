<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusListRulesTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_list_rules';
    }

    public function getDescription(): string
    {
        return 'List the alerting and recording rules configured in Prometheus, grouped by rule group, '
            . 'including each rule\'s name, expression, state and health. Optionally filter by rule type.';
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
                'type' => [
                    'type' => 'string',
                    'description' => 'Optional filter: "alert" for alerting rules, "record" for recording rules. Defaults to all.',
                    'enum' => ['alert', 'record'],
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
            $result = $this->prometheusService->listRules(
                profileKey: $arguments['profile'] ?? null,
                type: $arguments['type'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'groups' => $result['data']['groups'] ?? [],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Prometheus rules: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
