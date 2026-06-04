<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasSearchTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_search';
    }

    public function getDescription(): string
    {
        return 'Full-text search across an Atlas content tree.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'atlas' => [
                    'type' => 'string',
                    'description' => 'Atlas account name, for example "engineering" or "hr".',
                ],
                'q' => [
                    'type' => 'string',
                    'description' => 'Search query.',
                ],
            ],
            'required' => ['atlas', 'q'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'atlas';
    }

    public function execute(array $arguments): array
    {
        $atlas = $arguments['atlas'] ?? '';
        $query = $arguments['q'] ?? '';
        if ($atlas === '' || $query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "atlas" and "q" are required']],
                'isError' => true,
            ];
        }

        try {
            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $this->atlasService->search($atlas, $query),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
