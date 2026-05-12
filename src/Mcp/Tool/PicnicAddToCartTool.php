<?php

namespace App\Mcp\Tool;

use App\Picnic\PicnicService;

class PicnicAddToCartTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_add_to_cart';
    }

    public function getDescription(): string
    {
        return 'Add a Picnic product to the shopping cart by product ID. Use picnic_search_products to find product IDs first.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'string',
                    'description' => 'The Picnic product ID (e.g. "s1000000") as returned by picnic_search_products',
                ],
                'count' => [
                    'type' => 'integer',
                    'description' => 'How many units to add. Defaults to 1.',
                    'minimum' => 1,
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key. Defaults to the first configured account.',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $productId = trim((string) ($arguments['product_id'] ?? ''));
        $count = (int) ($arguments['count'] ?? 1);
        $account = $arguments['account'] ?? null;

        if ($productId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "product_id" is required']],
                'isError' => true,
            ];
        }

        if ($count < 1) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "count" must be at least 1']],
                'isError' => true,
            ];
        }

        try {
            $cart = $this->picnicService->addToCart($productId, $count, $account);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'added' => ['product_id' => $productId, 'count' => $count],
                    'cart' => $cart,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error adding to Picnic cart: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
