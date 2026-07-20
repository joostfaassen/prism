<?php

namespace App\Integrations\Alertmanager\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Alertmanager\AlertmanagerService;

class AlertmanagerListSilencesTool implements ToolInterface
{
    public function __construct(
        private readonly AlertmanagerService $alertmanagerService,
    ) {
    }

    public function getName(): string
    {
        return 'alertmanager_list_silences';
    }

    public function getDescription(): string
    {
        return 'List silences configured in Alertmanager, including their matchers, state '
            . '(active/pending/expired), creator, comment and time window. Use this to see which '
            . 'alerts are currently muted and why. Optionally narrow with label matchers.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Alertmanager account key. Optional if only one account is configured.',
                ],
                'filters' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional label matchers, e.g. ["alertname=HighCpu"].',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'alertmanager';
    }

    public function execute(array $arguments): array
    {
        $filters = [];
        if (isset($arguments['filters']) && is_array($arguments['filters'])) {
            foreach ($arguments['filters'] as $filter) {
                if (is_string($filter) && $filter !== '') {
                    $filters[] = $filter;
                }
            }
        }

        try {
            $silences = $this->alertmanagerService->listSilences(
                accountKey: $arguments['account'] ?? null,
                filters: $filters,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($silences),
                    'silences' => $silences,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Alertmanager silences: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
