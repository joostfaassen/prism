<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicBrowseRecipesTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_browse_recipes';
    }

    public function getDescription(): string
    {
        return 'Browse Picnic cookbook recipes (saved, new, this week, user-defined, …). '
            . 'Returns recipe id, title, image_url, and segments. Use picnic_get_recipe for full details including ingredients.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'segment' => [
                    'type' => 'string',
                    'description' => 'Optional filter, e.g. SAVED_RECIPES, NEW_RECIPES, USER_DEFINED_RECIPES, THIS_WEEK_RECIPES',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'Picnic profile key. Defaults to the first configured profile.',
                ],
            ],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $segment = isset($arguments['segment']) ? trim((string) $arguments['segment']) : null;
        if ($segment === '') {
            $segment = null;
        }
        $profile = $arguments['profile'] ?? null;

        try {
            $result = $this->picnicService->browseRecipes($profile, $segment);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error browsing Picnic recipes: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
