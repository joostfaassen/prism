<?php

namespace App\Mcp\Tool;

use App\Picnic\PicnicService;

class PicnicSearchProductsTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_search_products';
    }

    public function getDescription(): string
    {
        return 'Search for Picnic grocery products by name. Returns a flat list of matching products with their IDs (needed for adding to the cart), names, unit quantities and prices.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search term, e.g. "melk" or "bananen"',
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
        $account = $arguments['account'] ?? null;

        if ($query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "query" is required and cannot be empty']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->picnicService->searchProducts($query, $account);
            unset($result['raw']);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'query' => $result['query'],
                    'count' => count($result['results']),
                    'products' => $result['results'],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching Picnic products: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
