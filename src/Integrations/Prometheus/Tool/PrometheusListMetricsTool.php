<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusListMetricsTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_list_metrics';
    }

    public function getDescription(): string
    {
        return 'List the metric names known to Prometheus (the values of the __name__ label). '
            . 'Use this to discover which metrics exist before writing a PromQL query. '
            . 'Supports an optional case-insensitive substring filter.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Prometheus account key. Optional if only one account is configured.',
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'Optional case-insensitive substring to filter metric names (e.g. "http", "node_cpu").',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of names to return. Defaults to 200.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'prometheus';
    }

    public function execute(array $arguments): array
    {
        try {
            $names = $this->prometheusService->listMetricNames($arguments['account'] ?? null);

            $filter = isset($arguments['filter']) ? trim((string) $arguments['filter']) : '';
            if ($filter !== '') {
                $names = array_values(array_filter(
                    $names,
                    static fn (string $name): bool => stripos($name, $filter) !== false,
                ));
            }

            $total = count($names);
            $limit = isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : 200;
            $names = array_slice($names, 0, $limit);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'total' => $total,
                    'returned' => count($names),
                    'metrics' => $names,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Prometheus metrics: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
