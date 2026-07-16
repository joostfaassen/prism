<?php

namespace App\Mcp\Tool;

use App\Alertmanager\AlertmanagerService;

class AlertmanagerListAlertGroupsTool implements ToolInterface
{
    public function __construct(
        private readonly AlertmanagerService $alertmanagerService,
    ) {
    }

    public function getName(): string
    {
        return 'alertmanager_list_alert_groups';
    }

    public function getDescription(): string
    {
        return 'List alerts grouped the way Alertmanager routes and displays them (by group labels and '
            . 'receiver), mirroring the Alertmanager UI. Use this instead of alertmanager_list_alerts when '
            . 'you want the grouped/routed view. Supports the same active/silenced/inhibited and label '
            . 'matcher filters.';
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
                    'description' => 'Optional label matchers, e.g. ["severity=critical"].',
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
            $groups = $this->alertmanagerService->listAlertGroups(
                accountKey: $arguments['account'] ?? null,
                active: $arguments['active'] ?? true,
                silenced: $arguments['silenced'] ?? false,
                inhibited: $arguments['inhibited'] ?? false,
                filters: $filters,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($groups),
                    'groups' => $groups,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Alertmanager alert groups: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
