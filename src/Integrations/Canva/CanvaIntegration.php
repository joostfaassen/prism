<?php

namespace App\Integrations\Canva;

use App\Integrations\IntegrationInterface;

class CanvaIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'canva';
    }

    public function getLabel(): string
    {
        return 'Canva';
    }

    public function getDescription(): string
    {
        return 'Canva designs — list designs and read design pages via OAuth-connected profiles.';
    }
}
