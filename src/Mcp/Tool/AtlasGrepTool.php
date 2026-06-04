<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasGrepTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_grep';
    }

    public function getDescription(): string
    {
        return 'Line-level search across Atlas content. Supports literal or regex queries, path scoping, glob filtering, and max results.';
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
                    'description' => 'Search query or regex.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Optional path under content/ to search within.',
                ],
                'regex' => [
                    'type' => 'boolean',
                    'description' => 'Treat q as a regular expression. Defaults to false.',
                ],
                'ignore_case' => [
                    'type' => 'boolean',
                    'description' => 'Use case-insensitive matching. Defaults to false.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Optional filename glob, for example "*.md".',
                ],
                'max' => [
                    'type' => 'integer',
                    'description' => 'Maximum matches to return. Defaults to 200, maximum 1000.',
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
                    $this->atlasService->grep(
                        $atlas,
                        $query,
                        $arguments['path'] ?? null,
                        (bool) ($arguments['regex'] ?? false),
                        (bool) ($arguments['ignore_case'] ?? false),
                        $arguments['glob'] ?? null,
                        (int) ($arguments['max'] ?? 200),
                    ),
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
