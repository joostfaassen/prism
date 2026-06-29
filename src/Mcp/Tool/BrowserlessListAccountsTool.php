<?php

namespace App\Mcp\Tool;

use App\Browserless\BrowserlessService;

class BrowserlessListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly BrowserlessService $browserlessService,
    ) {
    }

    public function getName(): string
    {
        return 'browserless_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Browserless instances. Returns account keys, labels and base URLs. '
            . 'Use the account key in other browserless tools to choose which instance to use. '
            . 'If only one account is configured, the account argument can be omitted elsewhere.';
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
        return 'browserless';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->browserlessService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Browserless accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
