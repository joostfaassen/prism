<?php

namespace App\Integrations\Picnic;

use App\Integrations\IntegrationInterface;

class PicnicIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'picnic';
    }

    public function getLabel(): string
    {
        return 'Picnic';
    }

    public function getDescription(): string
    {
        return 'Picnic online groceries — search products, manage the cart and deliveries.';
    }
}
