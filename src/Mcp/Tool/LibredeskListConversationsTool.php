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

Returns conversation summaries with UUID, subject, status, contact, and last message.
Use the UUID with libredesk_get_conversation, libredesk_send_message, etc.
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

        try {
            $result = $this->libredeskService->listConversations($accountKey, $view, $page, $pageSize);

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
