<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusLabelValuesTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_label_values';
    }

    public function getDescription(): string
    {
        return 'List the distinct values of a Prometheus label, e.g. all "job", "instance" or '
            . '"namespace" values. Useful for discovering what to filter on before writing a PromQL '
            . 'query. Optionally restrict to specific series with one or more selectors.';
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
                'label' => [
                    'type' => 'string',
                    'description' => 'The label name to list values for, e.g. "job", "instance", "namespace".',
                ],
                'match' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional series selectors to restrict the result, e.g. ["up", "node_cpu_seconds_total{mode=\"idle\"}"].',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of values to return. Defaults to 500.',
                ],
            ],
            'required' => ['label'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'prometheus';
    }

    public function execute(array $arguments): array
    {
        $label = trim((string) ($arguments['label'] ?? ''));

        if ($label === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "label" argument is required, e.g. "job".']],
                'isError' => true,
            ];
        }

        $match = [];
        if (isset($arguments['match']) && is_array($arguments['match'])) {
            foreach ($arguments['match'] as $selector) {
                if (is_string($selector) && $selector !== '') {
                    $match[] = $selector;
                }
            }
        }

        try {
            $values = $this->prometheusService->labelValues(
                profileKey: $arguments['profile'] ?? null,
                label: $label,
                match: $match,
            );

            $total = count($values);
            $limit = isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : 500;
            $values = array_slice($values, 0, $limit);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'label' => $label,
                    'total' => $total,
                    'returned' => count($values),
                    'values' => $values,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Prometheus label values: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
