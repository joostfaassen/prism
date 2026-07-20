<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicAddRecipeToCartTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_add_recipe_to_cart';
    }

    public function getDescription(): string
    {
        return 'Add a Picnic recipe (selling group) ingredients to the shopping cart/list. '
            . 'Pass recipe_id from picnic_browse_recipes / picnic_get_recipe. Returns the updated cart summary.';
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
                'portions' => [
                    'type' => 'integer',
                    'description' => 'Optional number of servings',
                    'minimum' => 1,
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
        $portions = isset($arguments['portions']) ? (int) $arguments['portions'] : null;

        if ($recipeId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "recipe_id" is required']],
                'isError' => true,
            ];
        }

        if ($portions !== null && $portions < 1) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "portions" must be at least 1']],
                'isError' => true,
            ];
        }

        try {
            $cart = $this->picnicService->addRecipeToCart($recipeId, $portions, $profile);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'added_recipe_id' => $recipeId,
                    'portions' => $portions,
                    'cart' => $cart,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error adding Picnic recipe to cart: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
