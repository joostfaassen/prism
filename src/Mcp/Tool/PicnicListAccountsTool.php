<?php

namespace App\Mcp\Tool;

use App\Picnic\PicnicService;

class PicnicListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List the configured Picnic accounts available to this server.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->picnicService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Picnic accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
