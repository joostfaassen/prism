<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicRemoveRecipeFromCartTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_remove_recipe_from_cart';
    }

    public function getDescription(): string
    {
        return 'Remove a previously added Picnic recipe (selling group) from the shopping cart/list.';
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
            $cart = $this->picnicService->removeRecipeFromCart($recipeId, $profile);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'removed_recipe_id' => $recipeId,
                    'cart' => $cart,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error removing Picnic recipe from cart: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
