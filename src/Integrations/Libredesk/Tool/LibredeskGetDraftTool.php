<?php

namespace App\Integrations\Libredesk\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Libredesk\LibredeskService;

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
            $result = $this->libredeskService->getDraft($profileKey, $uuid);

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
