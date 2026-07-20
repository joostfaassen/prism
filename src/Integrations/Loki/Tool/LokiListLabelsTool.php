<?php

namespace App\Integrations\Loki\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Loki\LokiService;

class LokiListLabelsTool implements ToolInterface
{
    public function __construct(
        private readonly LokiService $lokiService,
    ) {
    }

    public function getName(): string
    {
        return 'loki_list_labels';
    }

    public function getDescription(): string
    {
        return 'List the label names known to Loki within a time range (e.g. "app", "namespace", "level"). '
            . 'Use this to discover which labels are available for building a LogQL stream selector. '
            . 'Then use loki_label_values to see the values of a specific label.';
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
                'start' => [
                    'type' => 'string',
                    'description' => 'Optional start time (RFC3339 or Unix timestamp). Defaults to 6 hours ago in Loki.',
                ],
                'end' => [
                    'type' => 'string',
                    'description' => 'Optional end time (RFC3339 or Unix timestamp). Defaults to now.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'loki';
    }

    public function execute(array $arguments): array
    {
        try {
            $labels = $this->lokiService->listLabels(
                profileKey: $arguments['profile'] ?? null,
                start: isset($arguments['start']) ? (string) $arguments['start'] : null,
                end: isset($arguments['end']) ? (string) $arguments['end'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($labels),
                    'labels' => $labels,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Loki labels: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
