<?php

namespace App\Integrations\Instagram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Instagram\InstagramService;

class InstagramListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List configured Instagram (Business/Creator) profiles. Returns each profile key, label, '
            . 'username, Instagram user id, whether credentials are present, whether the long-lived token can '
            . 'be auto-refreshed, and how many days until the token expires. Use the profile key in other '
            . 'Instagram tools. If only one profile is configured, the profile argument can be omitted elsewhere.';
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
        return 'instagram';
    }

    public function execute(array $arguments): array
    {
        try {
            $profiles = $this->instagramService->listProfiles();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($profiles),
                    'profiles' => $profiles,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Instagram profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
