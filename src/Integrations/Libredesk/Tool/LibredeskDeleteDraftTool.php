<?php

namespace App\Integrations\Libredesk\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Libredesk\LibredeskService;

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
                'profile' => [
                    'type' => 'string',
                    'description' => 'Libredesk profile key',
                ],
                'uuid' => [
                    'type' => 'string',
                    'description' => 'Conversation UUID',
                ],
            ],
            'required' => ['profile', 'uuid'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        $uuid = $arguments['uuid'] ?? '';

        if ($profileKey === '' || $uuid === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "profile" and "uuid" are required']],
                'isError' => true,
            ];
        }

        try {
            $this->libredeskService->deleteDraft($profileKey, $uuid);

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
