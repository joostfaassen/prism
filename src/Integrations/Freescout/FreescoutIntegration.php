<?php

namespace App\Integrations\Freescout;

use App\Integrations\IntegrationInterface;

class FreescoutIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'freescout';
    }

    public function getLabel(): string
    {
        return 'FreeScout';
    }

    public function getDescription(): string
    {
        return 'FreeScout helpdesk — mailboxes, conversations, users and replies.';
    }
}
