<?php

namespace App\Integrations\Apify;

use App\Integrations\IntegrationInterface;

class ApifyIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'apify';
    }

    public function getLabel(): string
    {
        return 'Apify';
    }

    public function getDescription(): string
    {
        return 'Apify actor platform — run scraping actors (Instagram, LinkedIn, web search) and fetch results.';
    }
}
