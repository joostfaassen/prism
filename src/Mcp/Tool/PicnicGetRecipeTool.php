<?php

namespace App\Mcp\Tool;

use App\Picnic\PicnicService;

class PicnicGetRecipeTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_get_recipe';
    }

    public function getDescription(): string
    {
        return 'Get a Picnic recipe by id (24–32 hex selling_group_id) or picnic.app recipe URL. '
            . 'Returns title, image_url, portions, ingredients, instructions, tips, and source_url. '
            . 'Images are HTTPS URLs — download outside Prism if needed.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => [
                    'type' => 'string',
                    'description' => 'Recipe id (selling_group_id) or full picnic.app recipe URL',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key. Defaults to the first configured account.',
                ],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $recipeId = trim((string) ($arguments['recipe_id'] ?? ''));
        $account = $arguments['account'] ?? null;

        if ($recipeId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "recipe_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $recipe = $this->picnicService->getRecipe($recipeId, $account);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $recipe,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Picnic recipe: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
