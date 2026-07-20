<?php

namespace App\Integrations\Instagram;

use App\Integrations\IntegrationInterface;

class InstagramIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'instagram';
    }

    public function getLabel(): string
    {
        return 'Instagram';
    }

    public function getDescription(): string
    {
        return 'Instagram Graph API — media, comments, insights, publishing and profile discovery.';
    }
}
