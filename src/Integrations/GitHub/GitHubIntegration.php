<?php

namespace App\Integrations\GitHub;

use App\Integrations\IntegrationInterface;

class GitHubIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'github';
    }

    public function getLabel(): string
    {
        return 'GitHub';
    }

    public function getDescription(): string
    {
        return 'GitHub — account activity and issue/PR search.';
    }
}
