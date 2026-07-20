<?php

namespace App\Integrations\Cyans;

use App\Integrations\IntegrationInterface;

class CyansIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'cyans';
    }

    public function getLabel(): string
    {
        return 'Cyans';
    }

    public function getDescription(): string
    {
        return 'Cyans topic tracking — list, search and read topics; add posts.';
    }
}
