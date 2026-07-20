<?php

namespace App\Integrations\Alertmanager\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Alertmanager\AlertmanagerService;

class AlertmanagerListAlertsTool implements ToolInterface
{
    public function __construct(
        private readonly AlertmanagerService $alertmanagerService,
    ) {
    }

    public function getName(): string
    {
        return 'alertmanager_list_alerts';
    }

    public function getDescription(): string
    {
        return 'List alerts currently held by Alertmanager, including their labels, annotations, '
            . 'status (active/suppressed), receivers and start time. By default returns active alerts only. '
            . 'Use label matchers to narrow results, e.g. ["severity=critical", "job=~api.*"].';
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
                'active' => [
                    'type' => 'boolean',
                    'description' => 'Include active (firing) alerts. Defaults to true.',
                ],
                'silenced' => [
                    'type' => 'boolean',
                    'description' => 'Include silenced alerts. Defaults to false.',
                ],
                'inhibited' => [
                    'type' => 'boolean',
                    'description' => 'Include inhibited alerts. Defaults to false.',
                ],
                'filters' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional label matchers, e.g. ["severity=critical", "alertname=~Cpu.*"].',
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
            $alerts = $this->alertmanagerService->listAlerts(
                accountKey: $arguments['account'] ?? null,
                active: $arguments['active'] ?? true,
                silenced: $arguments['silenced'] ?? false,
                inhibited: $arguments['inhibited'] ?? false,
                filters: $filters,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($alerts),
                    'alerts' => $alerts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Alertmanager alerts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
