<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskDeleteDraftTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_delete_draft';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Delete the DRAFT reply staged on a Libredesk conversation (identified by UUID) for the
agent that owns the configured API key. This only discards the staged draft; it does not
affect any sent messages.

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
            $this->libredeskService->deleteDraft($accountKey, $uuid);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    ['success' => true],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
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
