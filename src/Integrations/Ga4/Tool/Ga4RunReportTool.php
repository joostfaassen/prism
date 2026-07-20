<?php

namespace App\Integrations\Ga4\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Ga4\Ga4Service;

class Ga4RunReportTool implements ToolInterface
{
    public function __construct(
        private readonly Ga4Service $ga4Service,
    ) {
    }

    public function getName(): string
    {
        return 'ga4_run_report';
    }

    public function getDescription(): string
    {
        return 'Run an arbitrary Google Analytics 4 (GA4) Data API runReport query and return the raw result. '
            . 'Combine any dimensions and metrics over one or more date ranges, with optional filters, ordering, '
            . 'limit and offset. Discover valid dimension/metric names with ga4_get_metadata. '
            . 'Dates accept "YYYY-MM-DD", "today", "yesterday", or relative values like "7daysAgo"/"28daysAgo".';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'GA4 profile key. Optional if only one profile is configured.',
                ],
                'property_id' => [
                    'type' => 'string',
                    'description' => 'GA4 property id (numeric, e.g. "365738680"). Optional if a default property_id is configured for the profile.',
                ],
                'dimensions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Dimension API names, e.g. ["date", "country", "pagePath"]. May be empty.',
                ],
                'metrics' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Metric API names, e.g. ["activeUsers", "sessions", "screenPageViews"]. At least one is required.',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Start of a single date range. Defaults to "28daysAgo". Ignored if date_ranges is provided.',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'End of a single date range. Defaults to "today". Ignored if date_ranges is provided.',
                ],
                'date_ranges' => [
                    'type' => 'array',
                    'description' => 'Optional explicit list of date ranges, each an object {"start_date": "...", "end_date": "..."}. Overrides start_date/end_date.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'start_date' => ['type' => 'string'],
                            'end_date' => ['type' => 'string'],
                        ],
                        'required' => ['start_date', 'end_date'],
                    ],
                ],
                'dimension_filter' => [
                    'type' => 'object',
                    'description' => 'Optional GA4 FilterExpression applied to dimensions (passed through verbatim to the Data API).',
                    'additionalProperties' => true,
                ],
                'metric_filter' => [
                    'type' => 'object',
                    'description' => 'Optional GA4 FilterExpression applied to metrics (passed through verbatim to the Data API).',
                    'additionalProperties' => true,
                ],
                'order_bys' => [
                    'type' => 'array',
                    'description' => 'Optional list of GA4 OrderBy objects (passed through verbatim), e.g. [{"metric": {"metricName": "sessions"}, "desc": true}].',
                    'items' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of rows to return.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Row offset for pagination.',
                ],
            ],
            'required' => ['metrics'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'ga4';
    }

    public function execute(array $arguments): array
    {
        $metrics = $this->stringList($arguments['metrics'] ?? null);
        if ($metrics === []) {
            return [
                'content' => [['type' => 'text', 'text' => 'At least one metric is required in "metrics", e.g. ["activeUsers"].']],
                'isError' => true,
            ];
        }

        $dimensions = $this->stringList($arguments['dimensions'] ?? null);
        $dateRanges = $this->resolveDateRanges($arguments);

        try {
            $result = $this->ga4Service->runReport(
                profileKey: $arguments['profile'] ?? null,
                propertyId: isset($arguments['property_id']) ? (string) $arguments['property_id'] : null,
                dimensions: $dimensions,
                metrics: $metrics,
                dateRanges: $dateRanges,
                dimensionFilter: isset($arguments['dimension_filter']) && is_array($arguments['dimension_filter'])
                    ? $arguments['dimension_filter'] : null,
                metricFilter: isset($arguments['metric_filter']) && is_array($arguments['metric_filter'])
                    ? $arguments['metric_filter'] : null,
                orderBys: isset($arguments['order_bys']) && is_array($arguments['order_bys'])
                    ? array_values($arguments['order_bys']) : null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : null,
                offset: isset($arguments['offset']) ? (int) $arguments['offset'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'dimensions' => $dimensions,
                    'metrics' => $metrics,
                    'dateRanges' => $dateRanges,
                    'result' => $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running GA4 report: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{startDate: string, endDate: string}>
     */
    private function resolveDateRanges(array $arguments): array
    {
        if (isset($arguments['date_ranges']) && is_array($arguments['date_ranges']) && $arguments['date_ranges'] !== []) {
            $ranges = [];
            foreach ($arguments['date_ranges'] as $range) {
                if (!is_array($range)) {
                    continue;
                }
                $start = $range['start_date'] ?? null;
                $end = $range['end_date'] ?? null;
                if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
                    $ranges[] = ['startDate' => $start, 'endDate' => $end];
                }
            }
            if ($ranges !== []) {
                return $ranges;
            }
        }

        return [[
            'startDate' => isset($arguments['start_date']) && is_string($arguments['start_date']) && $arguments['start_date'] !== ''
                ? $arguments['start_date'] : '28daysAgo',
            'endDate' => isset($arguments['end_date']) && is_string($arguments['end_date']) && $arguments['end_date'] !== ''
                ? $arguments['end_date'] : 'today',
        ]];
    }
}
