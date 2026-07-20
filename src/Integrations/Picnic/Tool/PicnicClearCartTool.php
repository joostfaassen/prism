<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicClearCartTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_clear_cart';
    }

    public function getDescription(): string
    {
        return 'Clear the entire Picnic shopping cart (the household shopping list). '
            . 'Destructive — requires confirm=true.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Must be true to clear the cart',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key. Defaults to the first configured account.',
                ],
            ],
            'required' => ['confirm'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        if (($arguments['confirm'] ?? false) !== true) {
            return [
                'content' => [['type' => 'text', 'text' => 'Refusing to clear cart without confirm=true']],
                'isError' => true,
            ];
        }

        $account = $arguments['account'] ?? null;

        try {
            $cart = $this->picnicService->clearCart($account);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $cart,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error clearing Picnic cart: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
