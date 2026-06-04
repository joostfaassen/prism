<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasContentTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_content';
    }

    public function getDescription(): string
    {
        return 'Read an Atlas content file. Markdown returns frontmatter and HTML, typed YAML returns data, and text returns text.';
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
                    'description' => 'File path under content/.',
                ],
            ],
            'required' => ['atlas', 'path'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'atlas';
    }

    public function execute(array $arguments): array
    {
        $atlas = $arguments['atlas'] ?? '';
        $path = $arguments['path'] ?? '';
        if ($atlas === '' || $path === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "atlas" and "path" are required']],
                'isError' => true,
            ];
        }

        try {
            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $this->atlasService->content($atlas, $path),
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
