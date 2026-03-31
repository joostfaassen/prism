<?php

namespace App\Mcp\Tool;

use App\Imap\ImapService;

class ImapListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly ImapService $imapService,
    ) {
    }

    public function getName(): string
    {
        return 'imap_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List all configured IMAP accounts';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->imapService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['accounts' => $accounts], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
