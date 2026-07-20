<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicUnsaveRecipeTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_unsave_recipe';
    }

    public function getDescription(): string
    {
        return 'Remove a Picnic recipe from the user saved/favourites cookbook.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => [
                    'type' => 'string',
                    'description' => 'Recipe id (selling_group_id) or picnic.app recipe URL',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'Picnic profile key. Defaults to the first configured profile.',
                ],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $recipeId = trim((string) ($arguments['recipe_id'] ?? ''));
        $profile = $arguments['profile'] ?? null;

        if ($recipeId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "recipe_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->picnicService->saveRecipe($recipeId, false, $profile);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error unsaving Picnic recipe: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
