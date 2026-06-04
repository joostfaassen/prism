<?php

namespace App\Mcp\Tool;

use App\Atlas\AtlasService;

class AtlasListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly AtlasService $atlasService,
    ) {
    }

    public function getName(): string
    {
        return 'atlas_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Atlas accounts. Returns Atlas names, labels, and base URLs. Use the key as the atlas argument in other atlas_* tools.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function getAccountType(): ?string
    {
        return 'atlas';
    }

    public function execute(array $arguments): array
    {
        try {
            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'accounts' => $this->atlasService->listAccounts(),
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
