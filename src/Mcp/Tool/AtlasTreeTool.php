<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasTreeTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_tree';
    }

    public function getDescription(): string
    {
        return 'Get a recursive Atlas content directory tree. Depth defaults to 3 and is capped at 12.';
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
                'depth' => [
                    'type' => 'integer',
                    'description' => 'Tree depth. Defaults to 3, maximum 12.',
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
                    $this->atlasService->tree($atlas, $arguments['path'] ?? '', (int) ($arguments['depth'] ?? 3)),
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
