<?php

namespace App\Mcp\Tool;

use App\Canva\CanvaService;

class CanvaGetDesignTool implements ToolInterface
{
    public function __construct(
        private readonly CanvaService $canvaService,
    ) {
    }

    public function getName(): string
    {
        return 'canva_get_design';
    }

    public function getDescription(): string
    {
        return 'Get the metadata for a single Canva design by its design ID. Returns owner information, edit and view URLs, thumbnail, title, and page count. Use canva_list_designs to discover design IDs.';
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
            $result = $this->canvaService->getDesign(
                accountKey: $arguments['account'] ?? null,
                designId: $designId,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting Canva design: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
