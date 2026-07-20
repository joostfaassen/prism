<?php

namespace App\Integrations\Transip\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Transip\TransipService;

class TransipListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured TransIP profiles. Returns profile keys, labels, the TransIP login, and whether the profile is read-only. Use the profile key in other TransIP tools to choose which profile to use. If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'transip';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->transipService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing TransIP profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
