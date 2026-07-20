<?php

namespace App\Integrations\Instagram\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Instagram\InstagramService;

class InstagramGetPublishingLimitTool implements ToolInterface
{
    public function __construct(
        private readonly InstagramService $instagramService,
    ) {
    }

    public function getName(): string
    {
        return 'instagram_get_publishing_limit';
    }

    public function getDescription(): string
    {
        return 'Check how many API-published posts remain in the rolling 24-hour window (Instagram caps profiles at '
            . '100 published posts per 24h; carousels count as one). Call this before bulk/scheduled publishing to '
            . 'avoid hitting the limit.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => ['type' => 'string', 'description' => 'Instagram profile key. Optional if only one is configured.'],
            ],
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
            $result = $this->instagramService->getPublishingLimit($arguments['profile'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Instagram publishing limit: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
