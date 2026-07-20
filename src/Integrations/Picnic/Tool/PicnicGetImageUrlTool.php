<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicImage;
use App\Integrations\Picnic\PicnicService;

class PicnicGetImageUrlTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_get_image_url';
    }

    public function getDescription(): string
    {
        return 'Build a public HTTPS URL for a Picnic product/recipe image_id. '
            . 'Sizes: tiny, small, medium (default), large, extra-large. No binary download — URL only.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'image_id' => [
                    'type' => 'string',
                    'description' => 'Image id from picnic_search / picnic_get_product / picnic_get_recipe',
                ],
                'size' => [
                    'type' => 'string',
                    'description' => 'Image size',
                    'enum' => PicnicImage::SIZES,
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key (used for country_code in the URL). Defaults to the first account.',
                ],
            ],
            'required' => ['image_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $imageId = trim((string) ($arguments['image_id'] ?? ''));
        $size = (string) ($arguments['size'] ?? 'medium');
        $account = $arguments['account'] ?? null;

        if ($imageId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "image_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->picnicService->getImageUrl($imageId, $size, $account);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error building Picnic image URL: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
