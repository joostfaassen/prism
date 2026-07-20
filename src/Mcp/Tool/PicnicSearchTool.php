<?php

namespace App\Mcp\Tool;

use App\Picnic\PicnicService;

class PicnicSearchTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_search';
    }

    public function getDescription(): string
    {
        return 'Search Picnic grocery products by name/keywords. Returns product ids (needed for picnic_add_to_cart), '
            . 'names, prices (cents + EUR), unit sizes, and image_url. Same as picnic_search_products.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term, e.g. "melk" or "bananen"',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max products to return (1–50, default 20)',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key. Defaults to the first configured account.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $limit = (int) ($arguments['limit'] ?? 20);
        $account = $arguments['account'] ?? null;

        if ($query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "query" is required and cannot be empty']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->picnicService->searchProducts($query, $account, $limit);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching Picnic products: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
