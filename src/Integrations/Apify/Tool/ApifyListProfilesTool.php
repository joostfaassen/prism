<?php

namespace App\Integrations\Apify\Tool;

use App\Integrations\Apify\ApifyService;
use App\Mcp\Tool\ToolInterface;

class ApifyListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly ApifyService $apifyService,
    ) {
    }

    public function getName(): string
    {
        return 'apify_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List the configured Apify profiles available on this server. Returns each profile key, label and API base URL. Use the key as the "profile" argument for other Apify tools.';
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
        return 'apify';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->apifyService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Apify profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
