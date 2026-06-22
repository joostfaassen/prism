<?php

namespace App\Mcp\Tool;

use App\Email\EmailService;

class EmailGetRawMessageTool implements ToolInterface
{
    public function __construct(
        private readonly EmailService $emailService,
    ) {
    }

    public function getName(): string
    {
        return 'email_get_raw_message';
    }

    public function getDescription(): string
    {
        return 'Fetch the complete, unparsed original RFC822/MIME source of a single message by UID '
            . '(headers + body, including encoded attachment parts). Useful for extracting attachments '
            . 'or inspecting raw headers. The \\Seen flag is not modified.';
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
                    'description' => 'Folder name',
                ],
                'uid' => [
                    'type' => 'integer',
                    'description' => 'Message UID to fetch',
                ],
                'encoding' => [
                    'type' => 'string',
                    'enum' => ['auto', 'raw', 'base64'],
                    'description' => 'Output encoding. "auto" (default) returns raw text when it is valid '
                        . 'UTF-8 and base64 otherwise; "raw" always returns text; "base64" always base64-encodes.',
                ],
            ],
            'required' => ['account', 'folder', 'uid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'email';
    }

    public function execute(array $arguments): array
    {
        $account = (string) ($arguments['account'] ?? '');
        $folder = (string) ($arguments['folder'] ?? '');
        $uid = $arguments['uid'] ?? null;
        $encoding = (string) ($arguments['encoding'] ?? 'auto');

        if ($account === '') {
            return $this->error('Parameter "account" is required');
        }

        if ($folder === '') {
            return $this->error('Parameter "folder" is required');
        }

        if (!is_int($uid) || $uid <= 0) {
            return $this->error('Parameter "uid" is required and must be a positive integer');
        }

        if (!in_array($encoding, ['auto', 'raw', 'base64'], true)) {
            return $this->error('Parameter "encoding" must be one of: auto, raw, base64');
        }

        try {
            $raw = $this->emailService->getRawMessage(
                accountId: $account,
                folder: $folder,
                uid: $uid,
            );

            $useBase64 = $encoding === 'base64'
                || ($encoding === 'auto' && !$this->isValidUtf8($raw));

            return [
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'account' => $account,
                        'folder' => $folder,
                        'uid' => $uid,
                        'size_bytes' => strlen($raw),
                        'encoding' => $useBase64 ? 'base64' : 'raw',
                        'raw' => $useBase64 ? base64_encode($raw) : $raw,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
            ];
        } catch (\Throwable $e) {
            return $this->error('Error fetching raw message: ' . $e->getMessage());
        }
    }

    private function isValidUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
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
