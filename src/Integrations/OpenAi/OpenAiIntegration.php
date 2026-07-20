<?php

namespace App\Integrations\OpenAi;

use App\Integrations\IntegrationInterface;

class OpenAiIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'openai';
    }

    public function getLabel(): string
    {
        return 'OpenAI';
    }

    public function getDescription(): string
    {
        return 'OpenAI-compatible APIs — list models and run completions.';
    }
}
