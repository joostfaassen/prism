<?php

namespace App\Mcp\Tool;

use App\Freescout\FreescoutService;

class FreescoutListConversationsTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_list_conversations';
    }

    public function getDescription(): string
    {
        return 'List conversations in a Freescout mailbox. Supports filtering by status (active, pending, closed, spam) and date. Returns conversation summaries with subject, customer, assignee, and timestamps.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Freescout account key',
                ],
                'mailbox_id' => [
                    'type' => 'integer',
                    'description' => 'Mailbox ID to filter by. Use freescout_list_mailboxes to find IDs.',
                ],
                'folder_id' => [
                    'type' => 'integer',
                    'description' => 'Folder ID to filter by (optional)',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status: active, pending, closed, or spam',
                    'enum' => ['active', 'pending', 'closed', 'spam'],
                ],
                'updated_since' => [
                    'type' => 'string',
                    'description' => 'Only conversations updated after this ISO 8601 date (e.g. "2025-01-01T00:00:00Z")',
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number (default 1)',
                ],
                'page_size' => [
                    'type' => 'integer',
                    'description' => 'Results per page (default 50, max 100)',
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

        $mailboxId = isset($arguments['mailbox_id']) ? (int) $arguments['mailbox_id'] : null;
        $folderId = isset($arguments['folder_id']) ? (int) $arguments['folder_id'] : null;
        $status = $arguments['status'] ?? null;
        $updatedSince = $arguments['updated_since'] ?? null;
        $page = (int) ($arguments['page'] ?? 1);
        $pageSize = min((int) ($arguments['page_size'] ?? 50), 100);

        try {
            $result = $this->freescoutService->listConversations(
                $accountKey,
                $mailboxId,
                $folderId,
                $status,
                $updatedSince,
                $page,
                $pageSize,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
