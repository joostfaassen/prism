<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusQueryRangeTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_query_range';
    }

    public function getDescription(): string
    {
        return 'Run a range PromQL query against Prometheus and return a time series of values between '
            . 'start and end at a fixed resolution step. Use this for graphs and trends, e.g. CPU usage '
            . 'over the last hour. For a single current value use prometheus_query instead.';
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
                'query' => [
                    'type' => 'string',
                    'description' => 'PromQL expression to evaluate, e.g. "rate(node_cpu_seconds_total[5m])".',
                ],
                'start' => [
                    'type' => 'string',
                    'description' => 'Start time: RFC3339 (e.g. "2026-06-30T11:00:00Z") or a Unix timestamp.',
                ],
                'end' => [
                    'type' => 'string',
                    'description' => 'End time: RFC3339 or a Unix timestamp.',
                ],
                'step' => [
                    'type' => 'string',
                    'description' => 'Resolution step, as a duration ("15s", "1m", "1h") or a number of seconds.',
                ],
            ],
            'required' => ['query', 'start', 'end', 'step'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'prometheus';
    }

    public function execute(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $start = trim((string) ($arguments['start'] ?? ''));
        $end = trim((string) ($arguments['end'] ?? ''));
        $step = trim((string) ($arguments['step'] ?? ''));

        if ($query === '' || $start === '' || $end === '' || $step === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "query", "start", "end" and "step" arguments are all required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->prometheusService->queryRange(
                profileKey: $arguments['profile'] ?? null,
                query: $query,
                start: $start,
                end: $end,
                step: $step,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'query' => $query,
                    'start' => $start,
                    'end' => $end,
                    'step' => $step,
                    'result' => $result['data'] ?? $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Prometheus range query: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
