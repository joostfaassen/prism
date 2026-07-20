<?php

namespace App\Integrations\Prometheus;

use App\Integrations\IntegrationInterface;

class PrometheusIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'prometheus';
    }

    public function getLabel(): string
    {
        return 'Prometheus';
    }

    public function getDescription(): string
    {
        return 'Prometheus — PromQL queries, metrics, alerts, rules and targets.';
    }
}
