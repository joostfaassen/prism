<?php

namespace App\Mcp\Tool;

use App\Loki\LokiService;

class LokiQueryRangeTool implements ToolInterface
{
    public function __construct(
        private readonly LokiService $lokiService,
    ) {
    }

    public function getName(): string
    {
        return 'loki_query_range';
    }

    public function getDescription(): string
    {
        return 'Run a LogQL query against Loki over a time range and return matching log lines (or metric '
            . 'values for metric queries). This is the main tool for searching logs, e.g. '
            . '\'{app="api"} |= "error"\' or \'sum(rate({app="api"} |= "error" [5m]))\'. '
            . 'Defaults to the last hour, newest first.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Loki account key. Optional if only one account is configured.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'LogQL expression, e.g. \'{app="api"} |= "error"\'. A log stream selector ({...}) is required.',
                ],
                'start' => [
                    'type' => 'string',
                    'description' => 'Start time: RFC3339 (e.g. "2026-06-30T11:00:00Z"), Unix seconds, or nanoseconds. Defaults to one hour before end.',
                ],
                'end' => [
                    'type' => 'string',
                    'description' => 'End time: RFC3339 or Unix timestamp. Defaults to now.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of log entries to return. Defaults to 100.',
                ],
                'direction' => [
                    'type' => 'string',
                    'description' => 'Sort direction: "backward" (newest first, default) or "forward".',
                    'enum' => ['backward', 'forward'],
                ],
                'step' => [
                    'type' => 'string',
                    'description' => 'Optional query resolution step for metric queries (duration like "30s" or seconds).',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'loki';
    }

    public function execute(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "query" argument is required, e.g. \'{app="api"} |= "error"\'.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->lokiService->queryRange(
                accountKey: $arguments['account'] ?? null,
                query: $query,
                start: isset($arguments['start']) ? (string) $arguments['start'] : null,
                end: isset($arguments['end']) ? (string) $arguments['end'] : null,
                limit: isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : 100,
                direction: ($arguments['direction'] ?? 'backward') === 'forward' ? 'forward' : 'backward',
                step: isset($arguments['step']) ? (string) $arguments['step'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'query' => $query,
                    'result' => $result['data'] ?? $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Loki range query: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
