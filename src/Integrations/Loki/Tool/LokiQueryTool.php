<?php

namespace App\Integrations\Loki\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Loki\LokiService;

class LokiQueryTool implements ToolInterface
{
    public function __construct(
        private readonly LokiService $lokiService,
    ) {
    }

    public function getName(): string
    {
        return 'loki_query';
    }

    public function getDescription(): string
    {
        return 'Run an instant LogQL query against Loki at a single point in time. Best for metric LogQL '
            . 'expressions that return a current value, e.g. \'sum(count_over_time({app="api"} |= "error" [5m]))\'. '
            . 'For browsing raw log lines over a window, use loki_query_range.';
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
                    'description' => 'LogQL expression to evaluate.',
                ],
                'time' => [
                    'type' => 'string',
                    'description' => 'Optional evaluation time: RFC3339 or Unix timestamp. Defaults to now.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of entries to return. Defaults to 100.',
                ],
                'direction' => [
                    'type' => 'string',
                    'description' => 'Sort direction: "backward" (default) or "forward".',
                    'enum' => ['backward', 'forward'],
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
                'content' => [['type' => 'text', 'text' => 'The "query" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->lokiService->query(
                accountKey: $arguments['account'] ?? null,
                query: $query,
                time: isset($arguments['time']) ? (string) $arguments['time'] : null,
                limit: isset($arguments['limit']) ? max(1, (int) $arguments['limit']) : 100,
                direction: ($arguments['direction'] ?? 'backward') === 'forward' ? 'forward' : 'backward',
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'query' => $query,
                    'result' => $result['data'] ?? $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Loki query: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
