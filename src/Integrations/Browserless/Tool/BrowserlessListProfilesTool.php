<?php

namespace App\Integrations\Browserless\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Browserless\BrowserlessService;

class BrowserlessListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly BrowserlessService $browserlessService,
    ) {
    }

    public function getName(): string
    {
        return 'browserless_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Browserless instances. Returns profile keys, labels and base URLs. '
            . 'Use the profile key in other browserless tools to choose which instance to use. '
            . 'If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'browserless';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->browserlessService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Browserless profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
