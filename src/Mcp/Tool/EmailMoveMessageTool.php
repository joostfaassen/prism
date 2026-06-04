<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailMoveMessageTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_move_message';
    }

    public function getDescription(): string
    {
        return 'Move an email message by IMAP UID from one folder to another, for example from INBOX to Archive.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Email account ID.',
                ],
                'from_folder' => [
                    'type' => 'string',
                    'description' => 'Current folder containing the message. Default: INBOX',
                ],
                'uid' => [
                    'type' => 'integer',
                    'description' => 'Message UID in from_folder.',
                ],
                'to_folder' => [
                    'type' => 'string',
                    'description' => 'Destination folder, e.g. Archive.',
                ],
            ],
            'required' => ['account', 'uid', 'to_folder'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        $fromFolder = (string) ($arguments['from_folder'] ?? 'INBOX');
        $uid = $arguments['uid'] ?? null;
        $toFolder = (string) ($arguments['to_folder'] ?? '');

        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if ($fromFolder === '') {
            return $this->error('Parameter "from_folder" must be a non-empty string');
        }

        if (!is_int($uid) || $uid <= 0) {
            return $this->error('Parameter "uid" is required and must be a positive integer');
        }

        if ($toFolder === '') {
            return $this->error('Parameter "to_folder" is required');
        }

        try {
            $result = $this->emailService->moveMessage(
                accountId: $account,
                fromFolder: $fromFolder,
                uid: $uid,
                toFolder: $toFolder,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error moving message: ' . $e->getMessage());
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
