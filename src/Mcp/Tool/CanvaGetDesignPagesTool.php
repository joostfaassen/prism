<?php

namespace App\Mcp\Tool;

use App\Canva\CanvaService;

class CanvaGetDesignPagesTool implements ToolInterface
{
    public function __construct(
        private readonly CanvaService $canvaService,
    ) {
    }

    public function getName(): string
    {
        return 'canva_get_design_pages';
    }

    public function getDescription(): string
    {
        return 'List metadata for the pages of a Canva design, including per-page thumbnails. Pages are one-indexed; use offset and limit to page through. Note: this is a Canva preview API and some design types (e.g. Canva docs) have no pages.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Canva account key (optional if only one account is configured).',
                ],
                'design_id' => [
                    'type' => 'string',
                    'description' => 'The Canva design ID (from canva_list_designs).',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'One-based page index to start from (1-500, default 1).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Number of pages to return, starting at offset (1-200, default 50).',
                ],
            ],
            'required' => ['design_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'canva';
    }

    public function execute(array $arguments): array
    {
        $designId = isset($arguments['design_id']) ? trim((string) $arguments['design_id']) : '';
        if ($designId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: "design_id" is required.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->canvaService->getDesignPages(
                accountKey: $arguments['account'] ?? null,
                designId: $designId,
                offset: isset($arguments['offset']) ? (int) $arguments['offset'] : null,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting Canva design pages: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
