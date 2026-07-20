<?php

namespace App\Integrations\Bunq;

use App\Integrations\IntegrationInterface;

class BunqIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'bunq';
    }

    public function getLabel(): string
    {
        return 'bunq';
    }

    public function getDescription(): string
    {
        return 'bunq online banking — list profiles and transactions, read transaction details and notes.';
    }
}
