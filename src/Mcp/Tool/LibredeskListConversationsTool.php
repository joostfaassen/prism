<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskListConversationsTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_list_conversations';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
List conversations in a Libredesk instance. Choose a view:

- "all" (default): every conversation
- "unassigned": not assigned to any agent or team
- "team_unassigned": assigned to a team but no agent yet
- "assigned": assigned to the current API key's agent

Optional server-side filters narrow the list before pagination:
- "inbox_id": only conversations in that inbox (each inbox is a separate support desk)
- "team_id": only conversations assigned to that team
- "status": only conversations with that status name (e.g. "Open", "Resolved", "Closed", "Snoozed")
- "order_by" + "order": sort by a conversation field (e.g. "created_at", "last_message_at") ascending or descending; combine order_by=created_at + order=asc to get the oldest first.

Returns conversation summaries with UUID, subject, status, contact, and last message.
Use the UUID with libredesk_get_conversation, libredesk_reply, libredesk_add_note, etc.
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Libredesk account key',
                ],
                'view' => [
                    'type' => 'string',
                    'description' => 'Which conversation list to return (default: all)',
                    'enum' => ['all', 'unassigned', 'team_unassigned', 'assigned'],
                ],
                'page' => [
                    'type' => 'integer',
                    'description' => 'Page number (default 1)',
                ],
                'page_size' => [
                    'type' => 'integer',
                    'description' => 'Results per page (default 30, max 100)',
                ],
                'inbox_id' => [
                    'type' => 'integer',
                    'description' => 'Filter to a single inbox (support desk) by its numeric ID',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Filter to conversations assigned to this team ID',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by status name (e.g. Open, Resolved, Closed, Snoozed)',
                ],
                'order_by' => [
                    'type' => 'string',
                    'description' => 'Sort field, e.g. created_at, last_message_at, last_interaction_at, waiting_since',
                ],
                'order' => [
                    'type' => 'string',
                    'description' => 'Sort direction: asc or desc (default desc)',
                    'enum' => ['asc', 'desc'],
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'libredesk';
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

        $view = $arguments['view'] ?? 'all';
        $page = (int) ($arguments['page'] ?? 1);
        $pageSize = min((int) ($arguments['page_size'] ?? 30), 100);
        $inboxId = isset($arguments['inbox_id']) ? (int) $arguments['inbox_id'] : null;
        $teamId = isset($arguments['team_id']) ? (int) $arguments['team_id'] : null;
        $status = isset($arguments['status']) ? (string) $arguments['status'] : null;
        $orderBy = isset($arguments['order_by']) ? (string) $arguments['order_by'] : null;
        $order = isset($arguments['order']) ? (string) $arguments['order'] : null;

        try {
            $result = $this->libredeskService->listConversations(
                $accountKey,
                $view,
                $page,
                $pageSize,
                $inboxId,
                $teamId,
                $status,
                $orderBy,
                $order,
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
