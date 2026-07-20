<?php

namespace App\Integrations\OpenAi\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\OpenAi\OpenAiService;

class OpenAiListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly OpenAiService $openAiService,
    ) {
    }

    public function getName(): string
    {
        return 'openai_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured OpenAI-compatible profiles. Returns profile keys, labels, base URLs, and default models. Use the profile key in other openai tools to choose which endpoint to use. If only one profile is configured, the profile argument can be omitted elsewhere.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'openai';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->openAiService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing OpenAI profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
