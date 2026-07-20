<?php

namespace App\Integrations\Loki;

use App\Integrations\IntegrationInterface;

class LokiIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'loki';
    }

    public function getLabel(): string
    {
        return 'Loki';
    }

    public function getDescription(): string
    {
        return 'Grafana Loki — LogQL queries, labels and label values.';
    }
}
