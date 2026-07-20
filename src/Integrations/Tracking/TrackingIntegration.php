<?php

namespace App\Integrations\Tracking;

use App\Integrations\IntegrationInterface;

class TrackingIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'tracking';
    }

    public function getLabel(): string
    {
        return 'GPS Tracking';
    }

    public function getDescription(): string
    {
        return 'GPS tracking — devices, zones, traces and zone events (database-backed).';
    }
}
