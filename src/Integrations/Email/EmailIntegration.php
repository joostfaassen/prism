<?php

namespace App\Integrations\Email;

use App\Integrations\IntegrationInterface;

class EmailIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'email';
    }

    public function getLabel(): string
    {
        return 'Email';
    }

    public function getDescription(): string
    {
        return 'IMAP/SMTP email — search, read, send, move and flag messages across folders.';
    }
}
