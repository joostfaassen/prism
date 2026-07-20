<?php

namespace App\Integrations\Browserless;

use App\Integrations\IntegrationInterface;

class BrowserlessIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'browserless';
    }

    public function getLabel(): string
    {
        return 'Browserless';
    }

    public function getDescription(): string
    {
        return 'Headless Chrome via Browserless — screenshots, PDFs, page content and performance metrics.';
    }
}
