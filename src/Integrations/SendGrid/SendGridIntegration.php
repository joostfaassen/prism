<?php

namespace App\Integrations\SendGrid;

use App\Integrations\IntegrationInterface;

class SendGridIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'sendgrid';
    }

    public function getLabel(): string
    {
        return 'SendGrid';
    }

    public function getDescription(): string
    {
        return 'SendGrid — global, category and single-send email statistics.';
    }
}
