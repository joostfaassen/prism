<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailMultiSearchTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_multi_search';
    }

    public function getDescription(): string
    {
        return 'Search messages across multiple folders in one call.';
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
                'folders' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Folders to search. Defaults to [INBOX]',
                ],
                'from' => ['type' => 'string', 'description' => 'Sender match'],
                'to' => ['type' => 'string', 'description' => 'Recipient match'],
                'subject' => ['type' => 'string', 'description' => 'Subject substring'],
                'body' => ['type' => 'string', 'description' => 'Body substring'],
                'since' => ['type' => 'string', 'description' => 'ISO 8601 date'],
                'before' => ['type' => 'string', 'description' => 'ISO 8601 date'],
                'unseen_only' => ['type' => 'boolean', 'description' => 'Unread only'],
                'flagged_only' => ['type' => 'boolean', 'description' => 'Flagged only'],
                'limit_per_folder' => [
                    'type' => 'integer',
                    'description' => 'Max results per folder. Default: 20, max: 100',
                ],
                'offset' => ['type' => 'integer', 'description' => 'Pagination offset. Default: 0'],
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

        $folders = $arguments['folders'] ?? ['INBOX'];
        if (!is_array($folders)) {
            return $this->error('Parameter "folders" must be an array of folder names');
        }

        $normalizedFolders = [];
        foreach ($folders as $folder) {
            if (!is_string($folder)) {
                return $this->error('Parameter "folders" must contain only strings');
            }
            $trimmed = trim($folder);
            if ($trimmed !== '') {
                $normalizedFolders[] = $trimmed;
            }
        }

        $limit = (int) ($arguments['limit_per_folder'] ?? 20);
        $limit = max(1, min($limit, 100));

        try {
            $result = $this->emailService->multiSearch(
                accountId: $account,
                folders: $normalizedFolders,
                from: $arguments['from'] ?? null,
                to: $arguments['to'] ?? null,
                subject: $arguments['subject'] ?? null,
                body: $arguments['body'] ?? null,
                since: $arguments['since'] ?? null,
                before: $arguments['before'] ?? null,
                unseenOnly: (bool) ($arguments['unseen_only'] ?? false),
                flaggedOnly: (bool) ($arguments['flagged_only'] ?? false),
                limitPerFolder: $limit,
                offset: (int) ($arguments['offset'] ?? 0),
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error running multi-search: ' . $e->getMessage());
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
