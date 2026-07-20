<?php

namespace App\Integrations\Slack;

use App\Integrations\IntegrationInterface;

class SlackIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'slack';
    }

    public function getLabel(): string
    {
        return 'Slack';
    }

    public function getDescription(): string
    {
        return 'Slack workspaces — channels, messages, threads, reactions and posting.';
    }
}
