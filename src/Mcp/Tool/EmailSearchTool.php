<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailSearchTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_search';
    }

    public function getDescription(): string
    {
        return 'Search messages in an email folder. All filter params are ANDed together. Returns message summaries (headers only).';
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
                'from' => [
                    'type' => 'string',
                    'description' => 'Sender match',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'Recipient match',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Subject substring',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Body substring',
                ],
                'since' => [
                    'type' => 'string',
                    'description' => 'ISO 8601 date — messages on or after this date',
                ],
                'before' => [
                    'type' => 'string',
                    'description' => 'ISO 8601 date — messages before this date',
                ],
                'unseen_only' => [
                    'type' => 'boolean',
                    'description' => 'Unread messages only',
                ],
                'flagged_only' => [
                    'type' => 'boolean',
                    'description' => 'Flagged messages only',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results to return. Default: 20, max: 100',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Offset for pagination. Default: 0',
                ],
                'warm_cache_days' => [
                    'type' => 'integer',
                    'description' => 'If > 0, pre-warm cache for recent messages in this folder before searching. Typical value: 7',
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
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "account" is required']],
                'isError' => true,
            ];
        }

        $limit = (int) ($arguments['limit'] ?? 20);
        $limit = max(1, min($limit, 100));
        $warmCacheDays = (int) ($arguments['warm_cache_days'] ?? 0);

        try {
            if ($warmCacheDays > 0) {
                $this->emailService->warmRecentCache(
                    accountId: $account,
                    folder: (string) ($arguments['folder'] ?? 'INBOX'),
                    days: $warmCacheDays,
                );
            }

            $result = $this->emailService->search(
                accountId: $account,
                folder: $arguments['folder'] ?? 'INBOX',
                from: $arguments['from'] ?? null,
                to: $arguments['to'] ?? null,
                subject: $arguments['subject'] ?? null,
                body: $arguments['body'] ?? null,
                since: $arguments['since'] ?? null,
                before: $arguments['before'] ?? null,
                unseenOnly: (bool) ($arguments['unseen_only'] ?? false),
                flaggedOnly: (bool) ($arguments['flagged_only'] ?? false),
                limit: $limit,
                offset: (int) ($arguments['offset'] ?? 0),
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error searching messages: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
