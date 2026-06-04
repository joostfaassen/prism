<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List all configured email accounts (IMAP for reading, SMTP for sending). Each account reports whether it can send mail, the From address it will use, and the configured Sent folder.';
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
        return 'email';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->emailService->listAccounts();

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
