<?php

namespace App\Integrations\Twilio;

use App\Integrations\IntegrationInterface;

class TwilioIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'twilio';
    }

    public function getLabel(): string
    {
        return 'Twilio';
    }

    public function getDescription(): string
    {
        return 'Twilio — calls and call transcriptions.';
    }
}
