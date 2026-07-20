<?php

namespace App\Integrations\Email\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Email\EmailService;

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
                'profile' => [
                    'type' => 'string',
                    'description' => 'Email profile ID',
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
            'required' => ['profile'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $profile = (string) ($arguments['profile'] ?? '');
        if ($profile === '') {
            return $this->error('Parameter "profile" is required');
        }

        try {
            $result = $this->emailService->warmRecentCache(
                profileId: $profile,
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
