<?php

namespace App\Integrations\Transip;

use App\Integrations\IntegrationInterface;

class TransipIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'transip';
    }

    public function getLabel(): string
    {
        return 'TransIP';
    }

    public function getDescription(): string
    {
        return 'TransIP — domains, DNS records and invoices.';
    }
}
