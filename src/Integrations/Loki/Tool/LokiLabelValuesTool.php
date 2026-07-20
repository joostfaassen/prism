<?php

namespace App\Integrations\Loki\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Loki\LokiService;

class LokiLabelValuesTool implements ToolInterface
{
    public function __construct(
        private readonly LokiService $lokiService,
    ) {
    }

    public function getName(): string
    {
        return 'loki_label_values';
    }

    public function getDescription(): string
    {
        return 'List the distinct values of a Loki label within a time range, e.g. all "app", "namespace" '
            . 'or "level" values. Useful for building a LogQL stream selector before querying logs.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Loki profile key. Optional if only one profile is configured.',
                ],
                'label' => [
                    'type' => 'string',
                    'description' => 'The label name to list values for, e.g. "app", "namespace", "level".',
                ],
                'start' => [
                    'type' => 'string',
                    'description' => 'Optional start time (RFC3339 or Unix timestamp). Defaults to 6 hours ago in Loki.',
                ],
                'end' => [
                    'type' => 'string',
                    'description' => 'Optional end time (RFC3339 or Unix timestamp). Defaults to now.',
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
        return 'loki';
    }

    public function execute(array $arguments): array
    {
        $label = trim((string) ($arguments['label'] ?? ''));
        if ($label === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "label" argument is required, e.g. "app".']],
                'isError' => true,
            ];
        }

        try {
            $values = $this->lokiService->labelValues(
                profileKey: $arguments['profile'] ?? null,
                label: $label,
                start: isset($arguments['start']) ? (string) $arguments['start'] : null,
                end: isset($arguments['end']) ? (string) $arguments['end'] : null,
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
                'content' => [['type' => 'text', 'text' => 'Error listing Loki label values: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
