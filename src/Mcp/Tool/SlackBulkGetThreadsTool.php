<?php

namespace App\Mcp\Tool;

use App\Slack\SlackService;

class SlackBulkGetThreadsTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_bulk_get_threads';
    }

    public function getDescription(): string
    {
        return 'Fetch multiple Slack threads in one call. Returns partial success details so one failed thread does not fail the whole request.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Slack account key',
                ],
                'threads' => [
                    'type' => 'array',
                    'description' => 'List of thread targets with channel and thread_ts',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'channel' => ['type' => 'string'],
                            'thread_ts' => ['type' => 'string'],
                        ],
                        'required' => ['channel', 'thread_ts'],
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max messages per thread (default 50, max 200)',
                ],
            ],
            'required' => ['account', 'threads'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        $accountKey = trim((string) ($arguments['account'] ?? ''));
        $threads = $arguments['threads'] ?? null;
        if ($accountKey === '') {
            return $this->error('Parameter "account" is required');
        }

        if (!is_array($threads) || $threads === []) {
            return $this->error('Parameter "threads" is required and must be a non-empty array');
        }

        if (count($threads) > 20) {
            return $this->error('Parameter "threads" can contain at most 20 items');
        }

        $normalizedThreads = [];
        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                return $this->error('Each thread item must be an object with "channel" and "thread_ts"');
            }
            $channel = trim((string) ($thread['channel'] ?? ''));
            $threadTs = trim((string) ($thread['thread_ts'] ?? ''));
            if ($channel === '' || $threadTs === '') {
                return $this->error('Each thread item requires non-empty "channel" and "thread_ts"');
            }
            $normalizedThreads[] = [
                'channel' => $channel,
                'thread_ts' => $threadTs,
            ];
        }

        $limit = max(1, min((int) ($arguments['limit'] ?? 50), 200));

        try {
            $result = $this->slackService->bulkGetThreads($accountKey, $normalizedThreads, $limit);

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error fetching threads: ' . $e->getMessage());
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
