<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskGetDraftTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_get_draft';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Get the existing DRAFT reply staged on a Libredesk conversation (identified by UUID)
for the agent that owns the configured API key. Useful to check what is already staged
before overwriting it with libredesk_upsert_draft. Returns an empty/blank result if no
draft exists.

Requires a Libredesk build from late December 2025 or newer (conversation drafts API).
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Libredesk account key',
                ],
                'uuid' => [
                    'type' => 'string',
                    'description' => 'Conversation UUID',
                ],
            ],
            'required' => ['account', 'uuid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $uuid = $arguments['uuid'] ?? '';

        if ($accountKey === '' || $uuid === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account" and "uuid" are required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->libredeskService->getDraft($accountKey, $uuid);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    ['draft' => $result],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
