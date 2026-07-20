<?php

namespace App\Integrations\Apify\Tool;

use App\Integrations\Apify\ApifyService;
use App\Mcp\Tool\ToolInterface;

/**
 * Generic, ad-hoc actor runner. Useful for experimenting with any actor
 * before building a dedicated, strongly-typed tool by extending
 * AbstractApifyActorTool. For production use, prefer a dedicated tool with a
 * proper input schema over this free-form runner.
 */
class ApifyRunActorTool implements ToolInterface
{
    public function __construct(
        private readonly ApifyService $apifyService,
    ) {
    }

    public function getName(): string
    {
        return 'apify_run_actor';
    }

    public function getDescription(): string
    {
        return 'Run any Apify actor synchronously with a free-form JSON input and return its default dataset items. General-purpose escape hatch — prefer a dedicated Apify tool when one exists for the actor.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Apify profile key (from apify_list_profiles). Optional if only one profile is configured.',
                ],
                'actor' => [
                    'type' => 'string',
                    'description' => 'Actor identifier, e.g. "apify/rag-web-browser", "username~actor-name" or a raw actor ID.',
                ],
                'input' => [
                    'type' => 'object',
                    'description' => 'Actor input JSON, matching the actor\'s own input schema (see apify_get_actor). Defaults to an empty object.',
                ],
                'max_items' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of dataset items to return.',
                ],
            ],
            'required' => ['actor'],
        ];
    }

    public function getProfileType(): ?string
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

        $input = $arguments['input'] ?? [];
        if (!is_array($input)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: "input" must be a JSON object.']],
                'isError' => true,
            ];
        }

        $options = [];
        if (isset($arguments['max_items']) && $arguments['max_items'] !== '') {
            $options['maxItems'] = (int) $arguments['max_items'];
        }

        try {
            $items = $this->apifyService->runActorSync(
                profileKey: $arguments['profile'] ?? null,
                actorId: (string) $arguments['actor'],
                input: $input,
                options: $options,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($items),
                    'items' => $items,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Apify actor: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
