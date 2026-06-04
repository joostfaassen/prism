<?php

namespace App\Mcp\Tool;

use App\Freescout\FreescoutService;

class FreescoutListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Freescout helpdesk accounts. Returns account keys and labels. Use the account key in other freescout_* tools.';
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
        return 'freescout';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->freescoutService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
