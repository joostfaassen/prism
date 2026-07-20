<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicGetProductTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_get_product';
    }

    public function getDescription(): string
    {
        return 'Get details for a Picnic product by id (e.g. s11295810 from picnic_search). '
            . 'Returns name, price, unit size, description highlights, and image URLs.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'string',
                    'description' => 'Picnic product / selling-unit id, e.g. "s11295810"',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'Picnic profile key. Defaults to the first configured profile.',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $productId = trim((string) ($arguments['product_id'] ?? ''));
        $profile = $arguments['profile'] ?? null;

        if ($productId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "product_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $product = $this->picnicService->getProduct($productId, $profile);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $product,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Picnic product: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
