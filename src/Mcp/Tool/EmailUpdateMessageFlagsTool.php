<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailUpdateMessageFlagsTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_update_message_flags';
    }

    public function getDescription(): string
    {
        return 'Set or clear IMAP message flags and custom labels by UID. Supports starred/Flagged, Seen, Answered, and custom IMAP keywords.';
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
                'folder' => [
                    'type' => 'string',
                    'description' => 'Folder containing the message. Default: INBOX',
                ],
                'uid' => [
                    'type' => 'integer',
                    'description' => 'Message UID in folder.',
                ],
                'starred' => [
                    'type' => 'boolean',
                    'description' => 'Alias for the IMAP \\Flagged flag.',
                ],
                'flagged' => [
                    'type' => 'boolean',
                    'description' => 'Set or clear the IMAP \\Flagged flag.',
                ],
                'seen' => [
                    'type' => 'boolean',
                    'description' => 'Set or clear the IMAP \\Seen flag.',
                ],
                'answered' => [
                    'type' => 'boolean',
                    'description' => 'Set or clear the IMAP \\Answered flag.',
                ],
                'add_labels' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Custom IMAP keywords to add. Do not include spaces.',
                ],
                'remove_labels' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Custom IMAP keywords to remove. Do not include spaces.',
                ],
            ],
            'required' => ['account', 'uid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        $folder = (string) ($arguments['folder'] ?? 'INBOX');
        $uid = $arguments['uid'] ?? null;

        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if ($folder === '') {
            return $this->error('Parameter "folder" must be a non-empty string');
        }

        if (!is_int($uid) || $uid <= 0) {
            return $this->error('Parameter "uid" is required and must be a positive integer');
        }

        try {
            $standardFlags = $this->normalizeStandardFlags($arguments);
            $addLabels = $this->normalizeLabels($arguments['add_labels'] ?? [], 'add_labels');
            $removeLabels = $this->normalizeLabels($arguments['remove_labels'] ?? [], 'remove_labels');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        if ($standardFlags === [] && $addLabels === [] && $removeLabels === []) {
            return $this->error('Provide at least one flag or label update.');
        }

        try {
            $result = $this->emailService->updateMessageFlags(
                accountId: $account,
                folder: $folder,
                uid: $uid,
                standardFlags: $standardFlags,
                addLabels: $addLabels,
                removeLabels: $removeLabels,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error updating message flags: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeStandardFlags(array $arguments): array
    {
        $result = [];

        foreach (['starred' => '\\Flagged', 'flagged' => '\\Flagged', 'seen' => '\\Seen', 'answered' => '\\Answered'] as $argument => $flag) {
            if (!array_key_exists($argument, $arguments)) {
                continue;
            }

            if (!is_bool($arguments[$argument])) {
                throw new \InvalidArgumentException(sprintf('Parameter "%s" must be a boolean', $argument));
            }

            $result[$flag] = $arguments[$argument];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function normalizeLabels(mixed $value, string $parameter): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Parameter "%s" must be an array of strings', $parameter));
        }

        $labels = [];
        foreach ($value as $label) {
            if (!is_string($label)) {
                throw new \InvalidArgumentException(sprintf('Parameter "%s" must contain only strings', $parameter));
            }

            $label = trim($label);

            if ($label === '') {
                continue;
            }

            if (!preg_match('/^[^\x00-\x20(){}\[\]%"\\\\]+$/', $label)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid label "%s": IMAP labels cannot contain spaces, control characters, or special IMAP flag characters',
                    $label,
                ));
            }

            $labels[] = $label;
        }

        return array_values(array_unique($labels));
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
