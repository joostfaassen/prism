<?php

namespace App\Integrations\Email\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Email\EmailService;

class EmailListFoldersTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_list_folders';
    }

    public function getDescription(): string
    {
        return 'List IMAP folders for an email profile with total/unread message counts.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Email profile ID (see email_list_profiles).',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Glob pattern, e.g. * or INBOX.*. Default: *',
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
        $pattern = (string) ($arguments['pattern'] ?? '*');

        if ($profile === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "profile" is required']],
                'isError' => true,
            ];
        }

        try {
            $folders = $this->emailService->listFolders($profile, $pattern);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['folders' => $folders], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing folders: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
