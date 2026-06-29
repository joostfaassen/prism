<?php

namespace App\Mcp\Tool\Apify;

use App\Apify\ApifyService;
use App\Mcp\Tool\ToolInterface;

class ApifyGetActorTool implements ToolInterface
{
    public function __construct(
        private readonly ApifyService $apifyService,
    ) {
    }

    public function getName(): string
    {
        return 'apify_get_actor';
    }

    public function getDescription(): string
    {
        return 'Inspect an Apify actor: returns its metadata (id, name, title, description, default run options) and, where available, its input schema from the default build. Use this to understand an actor\'s inputs and outputs before wrapping it in a dedicated MCP tool.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Apify account key (from apify_list_accounts). Optional if only one account is configured.',
                ],
                'actor' => [
                    'type' => 'string',
                    'description' => 'Actor identifier, e.g. "apify/rag-web-browser", "username~actor-name" or a raw actor ID.',
                ],
            ],
            'required' => ['actor'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'apify';
    }

    public function execute(array $arguments): array
    {
        if (!isset($arguments['actor']) || trim((string) $arguments['actor']) === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: "actor" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->apifyService->getActor(
                accountKey: $arguments['account'] ?? null,
                actorId: (string) $arguments['actor'],
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Apify actor: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
