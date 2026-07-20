<?php

namespace App\Integrations\Libredesk;

use App\Integrations\IntegrationInterface;

class LibredeskIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'libredesk';
    }

    public function getLabel(): string
    {
        return 'Libredesk';
    }

    public function getDescription(): string
    {
        return 'Libredesk helpdesk — conversations, drafts, notes, statuses, agents and teams.';
    }
}
