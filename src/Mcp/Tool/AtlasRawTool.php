<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasRawTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_raw';
    }

    public function getDescription(): string
    {
        return 'Read raw Atlas file bytes as base64 with content type metadata. Use max_bytes to limit large binary files.';
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
                'max_bytes' => [
                    'type' => 'integer',
                    'description' => 'Maximum bytes to return before base64 encoding. Defaults to 1048576.',
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
                    $this->atlasService->raw($atlas, $path, (int) ($arguments['max_bytes'] ?? 1048576)),
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
