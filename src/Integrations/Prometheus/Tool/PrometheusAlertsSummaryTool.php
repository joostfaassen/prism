<?php

namespace App\Integrations\Prometheus\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Prometheus\PrometheusService;

class PrometheusAlertsSummaryTool implements ToolInterface
{
    public function __construct(
        private readonly PrometheusService $prometheusService,
    ) {
    }

    public function getName(): string
    {
        return 'prometheus_alerts_summary';
    }

    public function getDescription(): string
    {
        return 'Summarise the alerts currently active in Prometheus: counts by state (firing/pending) '
            . 'and by severity label, plus a per-alertname breakdown. Use this for quick triage instead '
            . 'of prometheus_list_alerts when you only need the big picture.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Prometheus account key. Optional if only one account is configured.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'prometheus';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->prometheusService->listAlerts($arguments['account'] ?? null);
            $alerts = $result['data']['alerts'] ?? [];
            if (!is_array($alerts)) {
                $alerts = [];
            }

            $byState = [];
            $bySeverity = [];
            $byAlertname = [];

            foreach ($alerts as $alert) {
                if (!is_array($alert)) {
                    continue;
                }

                $state = (string) ($alert['state'] ?? 'unknown');
                $byState[$state] = ($byState[$state] ?? 0) + 1;

                $labels = is_array($alert['labels'] ?? null) ? $alert['labels'] : [];
                $severity = (string) ($labels['severity'] ?? 'none');
                $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;

                $alertname = (string) ($labels['alertname'] ?? 'unknown');
                $byAlertname[$alertname] = ($byAlertname[$alertname] ?? 0) + 1;
            }

            arsort($byAlertname);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'total' => count($alerts),
                    'by_state' => $byState,
                    'by_severity' => $bySeverity,
                    'by_alertname' => $byAlertname,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error summarising Prometheus alerts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
