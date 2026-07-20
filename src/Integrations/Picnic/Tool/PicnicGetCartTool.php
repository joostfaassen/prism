<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicGetCartTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_get_cart';
    }

    public function getDescription(): string
    {
        return 'Get the current Picnic shopping cart (this is the household shopping list): '
            . 'line items, quantities, prices, totals, and image URLs.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key. Defaults to the first configured account.',
                ],
            ],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $account = $arguments['account'] ?? null;

        try {
            $cart = $this->picnicService->getCart($account);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $cart,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Picnic cart: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
