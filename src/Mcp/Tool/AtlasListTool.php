<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasListTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_list';
    }

    public function getDescription(): string
    {
        return 'List the direct children of a directory in an Atlas content tree.';
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
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory path under content/. Omit or pass an empty string for the root.',
                ],
            ],
            'required' => ['atlas'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'atlas';
    }

    public function execute(array $arguments): array
    {
        $atlas = $arguments['atlas'] ?? '';
        if ($atlas === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "atlas" is required']],
                'isError' => true,
            ];
        }

        try {
            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $this->atlasService->list($atlas, $arguments['path'] ?? ''),
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
