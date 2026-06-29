<?php

namespace App\Mcp\Tool;

use App\Canva\CanvaService;

class CanvaListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly CanvaService $canvaService,
    ) {
    }

    public function getName(): string
    {
        return 'canva_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Canva accounts. Returns each account key, label, whether it is connected (has a valid OAuth token), and the granted scopes. Use the account key in other Canva tools to choose which account to query. If only one account is configured, the account argument can be omitted elsewhere.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'canva';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->canvaService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Canva accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
