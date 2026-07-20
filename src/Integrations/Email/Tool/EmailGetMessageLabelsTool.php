<?php

namespace App\Integrations\Email\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Email\EmailService;

class EmailGetMessageLabelsTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_get_message_labels';
    }

    public function getDescription(): string
    {
        return 'Read IMAP flags and custom keyword tags/labels on one email message by UID. '
            . 'Returns standard flags (seen, flagged, answered, deleted, draft) plus the custom labels list. '
            . 'Use email_update_message_flags with add_labels/remove_labels to change tags.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Email profile ID.',
                ],
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder containing the message. Default: INBOX',
                ],
                'uid' => [
                    'type' => 'integer',
                    'description' => 'Message UID in folder.',
                ],
            ],
            'required' => ['profile', 'uid'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $profile = (string) ($arguments['profile'] ?? '');
        $folder = (string) ($arguments['folder'] ?? 'INBOX');
        $uid = $arguments['uid'] ?? null;

        if ($profile === '') {
            return $this->error('Parameter "profile" is required');
        }

        if ($folder === '') {
            return $this->error('Parameter "folder" must be a non-empty string');
        }

        if (!is_int($uid) || $uid <= 0) {
            return $this->error('Parameter "uid" is required and must be a positive integer');
        }

        try {
            $result = $this->emailService->getMessageLabels(
                profileId: $profile,
                folder: $folder,
                uid: $uid,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error reading message labels: ' . $e->getMessage());
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
