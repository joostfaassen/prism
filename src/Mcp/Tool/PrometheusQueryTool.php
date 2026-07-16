<?php

namespace App\Mcp\Tool;

use App\Prometheus\PrometheusService;

class PrometheusQueryTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_query';
    }

    public function getDescription(): string
    {
        return 'Run an instant PromQL query against Prometheus and return the result at a single point in time. '
            . 'Use this for current values, e.g. "up", "rate(http_requests_total[5m])", '
            . '"sum by (job) (up == 0)". For data over a time window use prometheus_query_range.';
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
                'query' => [
                    'type' => 'string',
                    'description' => 'PromQL expression to evaluate, e.g. "up" or "rate(node_cpu_seconds_total[5m])".',
                ],
                'time' => [
                    'type' => 'string',
                    'description' => 'Optional evaluation timestamp: RFC3339 (e.g. "2026-06-30T12:00:00Z") or a Unix timestamp. Defaults to now.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'prometheus';
    }

    public function execute(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "query" argument is required, e.g. "up".']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->prometheusService->query(
                accountKey: $arguments['account'] ?? null,
                query: $query,
                time: isset($arguments['time']) ? (string) $arguments['time'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'query' => $query,
                    'result' => $result['data'] ?? $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Prometheus query: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
