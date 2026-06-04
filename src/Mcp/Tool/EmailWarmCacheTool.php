<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailWarmCacheTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_warm_cache';
    }

    public function getDescription(): string
    {
        return 'Warm local IMAP cache with recent messages (defaults to last 7 days).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Email account ID',
                ],
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder name. Default: INBOX',
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Number of days to warm. Default: 7',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum messages to warm. Default: 200',
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        try {
            $result = $this->emailService->warmRecentCache(
                accountId: $account,
                folder: (string) ($arguments['folder'] ?? 'INBOX'),
                days: (int) ($arguments['days'] ?? 7),
                limit: (int) ($arguments['limit'] ?? 200),
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error warming cache: ' . $e->getMessage());
        }
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: true}
     */
    private function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
