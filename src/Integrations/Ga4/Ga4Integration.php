<?php

namespace App\Integrations\Ga4;

use App\Integrations\IntegrationInterface;

class Ga4Integration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'ga4';
    }

    public function getLabel(): string
    {
        return 'Google Analytics 4';
    }

    public function getDescription(): string
    {
        return 'GA4 Data API — run reports and read property metadata.';
    }
}
