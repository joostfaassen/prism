<?php

namespace App\Integrations\Igdb;

use App\Integrations\IntegrationInterface;

class IgdbIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'igdb';
    }

    public function getLabel(): string
    {
        return 'IGDB';
    }

    public function getDescription(): string
    {
        return 'IGDB games — look up and search games; normalized metadata with absolute cover URLs.';
    }
}
