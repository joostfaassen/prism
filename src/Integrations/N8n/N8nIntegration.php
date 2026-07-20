<?php

namespace App\Integrations\N8n;

use App\Integrations\IntegrationInterface;

class N8nIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'n8n';
    }

    public function getLabel(): string
    {
        return 'n8n';
    }

    public function getDescription(): string
    {
        return 'n8n workflow automation — inspect workflows and executions.';
    }
}
