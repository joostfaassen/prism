<?php

namespace App\Mcp\Tool;

use App\OpenAi\OpenAiService;

class OpenAiCompleteTool implements ToolInterface
{
    public function __construct(
        private readonly OpenAiService $openAiService,
    ) {
    }

    public function getName(): string
    {
        return 'openai_complete';
    }

    public function getDescription(): string
    {
        return 'Run a single chat completion against a configured OpenAI-compatible endpoint and return the assistant text. '
            . 'No tools/function-calling involved — just a straight prompt completion. '
            . 'Provide either "prompt" (with optional "system") for a one-shot completion, or "messages" for a full chat history. '
            . 'Useful for transforming text, e.g. correcting terminology in a transcript: put the instruction in "system" and the text in "prompt".';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'OpenAI account key. Omit to use the only configured account.',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'The user message / text to complete. Required unless "messages" is provided.',
                ],
                'system' => [
                    'type' => 'string',
                    'description' => 'Optional system instruction (e.g. "Correct the terminology in this transcript without changing meaning").',
                ],
                'messages' => [
                    'type' => 'array',
                    'description' => 'Full chat history. Overrides "prompt"/"system" if provided. Each item is {role, content} with role one of system/user/assistant.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'role' => [
                                'type' => 'string',
                                'enum' => ['system', 'user', 'assistant'],
                            ],
                            'content' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => ['role', 'content'],
                    ],
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Model to use. Defaults to the account\'s default_model.',
                ],
                'temperature' => [
                    'type' => 'number',
                    'description' => 'Optional sampling temperature.',
                ],
                'max_tokens' => [
                    'type' => 'integer',
                    'description' => 'Optional maximum number of tokens to generate.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'openai';
    }

    public function execute(array $arguments): array
    {
        $account = $arguments['account'] ?? null;
        $model = $arguments['model'] ?? null;
        $temperature = isset($arguments['temperature']) ? (float) $arguments['temperature'] : null;
        $maxTokens = isset($arguments['max_tokens']) ? (int) $arguments['max_tokens'] : null;

        $messages = $this->buildMessages($arguments);

        if ($messages === []) {
            return [
                'content' => [['type' => 'text', 'text' => 'Provide either "prompt" or a non-empty "messages" array.']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->openAiService->complete(
                accountKey: $account,
                messages: $messages,
                model: $model,
                temperature: $temperature,
                maxTokens: $maxTokens,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'text' => $result['text'],
                    'model' => $result['model'],
                    'finish_reason' => $result['finish_reason'],
                    'usage' => $result['usage'],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running completion: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(array $arguments): array
    {
        if (isset($arguments['messages']) && is_array($arguments['messages']) && $arguments['messages'] !== []) {
            $messages = [];
            foreach ($arguments['messages'] as $msg) {
                if (!is_array($msg) || !isset($msg['role'], $msg['content'])) {
                    continue;
                }
                $messages[] = [
                    'role' => (string) $msg['role'],
                    'content' => (string) $msg['content'],
                ];
            }

            return $messages;
        }

        $prompt = $arguments['prompt'] ?? '';
        if (!is_string($prompt) || $prompt === '') {
            return [];
        }

        $messages = [];
        $system = $arguments['system'] ?? null;
        if (is_string($system) && $system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }
}
