<?php

namespace App\Integrations\Freescout\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Freescout\FreescoutService;

class FreescoutListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Freescout helpdesk profiles. Returns profile keys and labels. Use the profile key in other freescout_* tools.';
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
        return 'freescout';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->freescoutService->listProfiles();

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
