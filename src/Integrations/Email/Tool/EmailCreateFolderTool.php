<?php

namespace App\Integrations\Email\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Email\EmailService;

class EmailCreateFolderTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_create_folder';
    }

    public function getDescription(): string
    {
        return 'Create a new IMAP folder on an email profile. Returns an error if the folder already exists.';
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
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder name to create, e.g. Archive or INBOX.Projects. Must not already exist.',
                ],
            ],
            'required' => ['profile', 'folder'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $profile = (string) ($arguments['profile'] ?? '');
        $folder = (string) ($arguments['folder'] ?? '');

        if ($profile === '') {
            return $this->error('Parameter "profile" is required');
        }

        if (trim($folder) === '') {
            return $this->error('Parameter "folder" is required');
        }

        try {
            $result = $this->emailService->createFolder($profile, $folder);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error creating folder: ' . $e->getMessage());
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
