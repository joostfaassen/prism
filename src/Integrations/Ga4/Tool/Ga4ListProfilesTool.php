<?php

namespace App\Integrations\Ga4\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Ga4\Ga4Service;

class Ga4ListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly Ga4Service $ga4Service,
    ) {
    }

    public function getName(): string
    {
        return 'ga4_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Google Analytics 4 (GA4) profiles. Returns profile keys, labels, and any default property id. Use the profile key in other GA4 tools to choose which property to query. If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'ga4';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->ga4Service->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing GA4 profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
