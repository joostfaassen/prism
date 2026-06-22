<?php

namespace App\Mcp\Tool;

use App\SendGrid\SendGridService;

class SendGridListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured SendGrid accounts. Returns account keys, labels and base URL (api.sendgrid.com or api.eu.sendgrid.com). Use the account key in other SendGrid tools to choose which account to query. If only one account is configured, the account argument can be omitted elsewhere.';
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
        return 'sendgrid';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->sendGridService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing SendGrid accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
