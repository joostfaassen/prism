<?php

namespace App\Integrations\Libredesk\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Libredesk\LibredeskService;

class LibredeskGetConversationTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_get_conversation';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Get a Libredesk conversation with its messages. Conversations are identified by UUID
(see libredesk_list_conversations or libredesk_search_conversations). Output formats:

- "simple" (default): Clean JSON with key fields, messages as plain text. Best for structured processing.
- "text": Plain text rendering optimized for reading. Compact, shows message flow clearly.
- "convo": Structured format with explicit participants and typed messages. Best for analysis.
- "full": Raw API response (conversation + messages). Use when you need raw HTML bodies or metadata.

Internal/private notes are excluded by default; set include_notes=true to include them.
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
                    'description' => 'Conversation UUID to retrieve',
                ],
                'format' => [
                    'type' => 'string',
                    'description' => 'Output format: simple (default), text, convo, or full',
                    'enum' => ['simple', 'text', 'convo', 'full'],
                ],
                'include_notes' => [
                    'type' => 'boolean',
                    'description' => 'Include internal/private notes in the output (default: false)',
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

        $format = $arguments['format'] ?? 'simple';
        $includeNotes = (bool) ($arguments['include_notes'] ?? false);

        try {
            $result = match ($format) {
                'text' => $this->libredeskService->getConversationText($profileKey, $uuid, $includeNotes),
                'convo' => json_encode(
                    $this->libredeskService->getConversationConvo($profileKey, $uuid, $includeNotes),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'full' => json_encode(
                    [
                        'conversation' => $this->libredeskService->getConversationRaw($profileKey, $uuid),
                        'messages' => $this->libredeskService->getMessages($profileKey, $uuid, $includeNotes),
                    ],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                default => json_encode(
                    $this->libredeskService->getConversationSimple($profileKey, $uuid, $includeNotes),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            };

            return [
                'content' => [['type' => 'text', 'text' => $result]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
