<?php

namespace App\Integrations\Libredesk\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Libredesk\LibredeskService;

class LibredeskListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Libredesk helpdesk profiles. Returns profile keys and labels. Use the profile key in other libredesk_* tools.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function getProfileType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->libredeskService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
