<?php

namespace App\Integrations\Atlas;

use App\Integrations\IntegrationInterface;

class AtlasIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'atlas';
    }

    public function getLabel(): string
    {
        return 'Atlas';
    }

    public function getDescription(): string
    {
        return 'Atlas content repositories — browse, search and read structured markdown content.';
    }
}
