<?php

namespace App\Mcp\Tool;

use App\Slack\SlackService;

class SlackResolveIdsTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_resolve_ids';
    }

    public function getDescription(): string
    {
        return 'Resolve Slack user and channel IDs to readable metadata in one call using cached directory data.';
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
                'user_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'User IDs to resolve (e.g. U01ABC123)',
                ],
                'channel_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Channel IDs to resolve (e.g. C01ABC123, D01XYZ789)',
                ],
            ],
            'required' => ['account'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        $accountKey = trim((string) ($arguments['account'] ?? ''));
        if ($accountKey === '') {
            return $this->error('Parameter "account" is required');
        }

        $userIds = $arguments['user_ids'] ?? [];
        $channelIds = $arguments['channel_ids'] ?? [];
        if (!is_array($userIds) || !is_array($channelIds)) {
            return $this->error('Parameters "user_ids" and "channel_ids" must be arrays when provided');
        }

        $normalizedUserIds = [];
        foreach ($userIds as $userId) {
            if (!is_string($userId)) {
                return $this->error('Parameter "user_ids" must contain strings only');
            }
            $trimmed = trim($userId);
            if ($trimmed !== '') {
                $normalizedUserIds[] = $trimmed;
            }
        }

        $normalizedChannelIds = [];
        foreach ($channelIds as $channelId) {
            if (!is_string($channelId)) {
                return $this->error('Parameter "channel_ids" must contain strings only');
            }
            $trimmed = trim($channelId);
            if ($trimmed !== '') {
                $normalizedChannelIds[] = $trimmed;
            }
        }

        try {
            $result = $this->slackService->resolveIds($accountKey, $normalizedUserIds, $normalizedChannelIds);

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error resolving IDs: ' . $e->getMessage());
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
