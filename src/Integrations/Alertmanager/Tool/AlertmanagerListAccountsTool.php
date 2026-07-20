<?php

namespace App\Integrations\Alertmanager\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Alertmanager\AlertmanagerService;

class AlertmanagerListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly AlertmanagerService $alertmanagerService,
    ) {
    }

    public function getName(): string
    {
        return 'alertmanager_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Alertmanager accounts. Returns account keys, labels, and base URLs. '
            . 'Use the account key in other Alertmanager tools to choose which instance to query. '
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
        return 'alertmanager';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->alertmanagerService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Alertmanager accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
