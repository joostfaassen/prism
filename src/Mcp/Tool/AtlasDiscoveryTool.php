<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasDiscoveryTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_discovery';
    }

    public function getDescription(): string
    {
        return 'Get the Atlas Content API discovery document for a configured Atlas account.';
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
            return $this->jsonResult($this->atlasService->discovery($atlas));
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function jsonResult(array $result): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )]],
        ];
    }
}
