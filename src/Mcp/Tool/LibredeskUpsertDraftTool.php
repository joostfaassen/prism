<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskUpsertDraftTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_upsert_draft';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Stage a DRAFT reply on a Libredesk conversation (identified by UUID) WITHOUT sending it.

The draft is saved against the agent that owns the configured API key. It is NEVER
sent automatically — when that agent opens the conversation in Libredesk, the draft
pre-fills their reply editor so they can review, tweak, and send it manually. Saving
again overwrites any existing draft for that agent on this conversation. Sending a
message clears the draft.

Use this instead of libredesk_reply when a human should approve/send the reply.

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
                    'description' => 'Libredesk account key. The draft appears for the agent owning this account\'s API key.',
                ],
                'uuid' => [
                    'type' => 'string',
                    'description' => 'Conversation UUID to stage the draft on',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Draft reply body (HTML or plain text)',
                ],
            ],
            'required' => ['account', 'uuid', 'content'],
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
        $content = $arguments['content'] ?? '';

        if ($accountKey === '' || $uuid === '' || trim((string) $content) === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account", "uuid", and "content" are required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->libredeskService->upsertDraft($accountKey, $uuid, $content);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    ['success' => true, 'draft' => $result],
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
