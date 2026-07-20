<?php

namespace App\Integrations\Libredesk\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Libredesk\LibredeskService;

class LibredeskListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Libredesk helpdesk accounts. Returns account keys and labels. Use the account key in other libredesk_* tools.';
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
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->libredeskService->listAccounts();

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
