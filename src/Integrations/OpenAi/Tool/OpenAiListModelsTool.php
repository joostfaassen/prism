<?php

namespace App\Integrations\OpenAi\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\OpenAi\OpenAiService;

class OpenAiListModelsTool implements ToolInterface
{
    public function __construct(
        private readonly OpenAiService $openAiService,
    ) {
    }

    public function getName(): string
    {
        return 'openai_list_models';
    }

    public function getDescription(): string
    {
        return 'List the models available on a configured OpenAI-compatible endpoint. Use this to discover valid model names for openai_complete.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'OpenAI profile key. Omit to use the only configured profile.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'openai';
    }

    public function execute(array $arguments): array
    {
        $profile = $arguments['profile'] ?? null;

        try {
            $models = $this->openAiService->listModels($profile);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($models),
                    'models' => $models,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing models: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
