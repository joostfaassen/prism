<?php

namespace App\Mcp\Tool;

use App\Freescout\FreescoutService;

class FreescoutListMailboxesTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_list_mailboxes';
    }

    public function getDescription(): string
    {
        return 'List mailboxes in a Freescout account. Returns mailbox IDs, names, and email addresses. Use mailbox IDs when listing conversations.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Freescout account key. Use freescout_list_accounts to see available accounts.',
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'freescout';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        if ($accountKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "account" is required']],
                'isError' => true,
            ];
        }

        try {
            $mailboxes = $this->freescoutService->listMailboxes($accountKey);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($mailboxes),
                    'mailboxes' => $mailboxes,
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
