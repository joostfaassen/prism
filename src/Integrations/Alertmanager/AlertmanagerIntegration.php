<?php

namespace App\Integrations\Alertmanager;

use App\Integrations\IntegrationInterface;

class AlertmanagerIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'alertmanager';
    }

    public function getLabel(): string
    {
        return 'Alertmanager';
    }

    public function getDescription(): string
    {
        return 'Prometheus Alertmanager — inspect alerts, alert groups, silences and status; create and expire silences.';
    }
}
