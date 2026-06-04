<?php

namespace App\Mcp\Tool;

use App\Freescout\FreescoutService;

class FreescoutGetConversationTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_get_conversation';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Get a Freescout conversation with its messages/threads. Supports multiple output formats:

- "simple" (default): Clean JSON with key fields, threads as plain text. Best for structured processing.
- "text": Plain text rendering optimized for reading. Compact, shows message flow clearly.
- "convo": Structured format with explicit participants and typed messages. Best for analysis.
- "full": Raw API response with all fields. Use when you need attachment info or raw HTML bodies.

Notes/internal messages are excluded by default; set include_notes=true to include them.
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Freescout account key',
                ],
                'conversation_id' => [
                    'type' => 'integer',
                    'description' => 'Conversation ID to retrieve',
                ],
                'format' => [
                    'type' => 'string',
                    'description' => 'Output format: simple (default), text, convo, or full',
                    'enum' => ['simple', 'text', 'convo', 'full'],
                ],
                'include_notes' => [
                    'type' => 'boolean',
                    'description' => 'Include internal notes in the output (default: false)',
                ],
            ],
            'required' => ['account', 'conversation_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'freescout';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $conversationId = isset($arguments['conversation_id']) ? (int) $arguments['conversation_id'] : 0;

        if ($accountKey === '' || $conversationId === 0) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account" and "conversation_id" are required']],
                'isError' => true,
            ];
        }

        $format = $arguments['format'] ?? 'simple';
        $includeNotes = (bool) ($arguments['include_notes'] ?? false);

        try {
            $result = match ($format) {
                'text' => $this->freescoutService->getConversationText($accountKey, $conversationId, $includeNotes),
                'convo' => json_encode(
                    $this->freescoutService->getConversationConvo($accountKey, $conversationId),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'full' => json_encode(
                    $this->freescoutService->getConversationRaw($accountKey, $conversationId),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                default => json_encode(
                    $this->freescoutService->getConversationSimple($accountKey, $conversationId),
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
