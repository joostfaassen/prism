<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusListAlertsTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_list_alerts';
    }

    public function getDescription(): string
    {
        return 'List the currently active alerts as seen by Prometheus itself (pending and firing), '
            . 'including their labels, annotations, state and activation time. This reflects the alerting '
            . 'rules evaluated by Prometheus before they are routed to Alertmanager.';
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
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'prometheus';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->prometheusService->listAlerts($arguments['profile'] ?? null);
            $alerts = $result['data']['alerts'] ?? [];

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => is_array($alerts) ? count($alerts) : 0,
                    'alerts' => $alerts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Prometheus alerts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
