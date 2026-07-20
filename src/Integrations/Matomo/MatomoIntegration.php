<?php

namespace App\Integrations\Matomo;

use App\Integrations\IntegrationInterface;

class MatomoIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'matomo';
    }

    public function getLabel(): string
    {
        return 'Matomo';
    }

    public function getDescription(): string
    {
        return 'Matomo analytics — sites, visit summaries, top pages and reports.';
    }
}
