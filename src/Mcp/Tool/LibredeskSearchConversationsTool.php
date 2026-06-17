<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

class LibredeskSearchConversationsTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_search_conversations';
    }

    public function getDescription(): string
    {
        return 'Search Libredesk conversations by free-text query (minimum 3 characters). Returns matching conversations with UUID, reference number, and subject.';
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
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query (minimum 3 characters)',
                ],
            ],
            'required' => ['account', 'query'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $query = $arguments['query'] ?? '';

        if ($accountKey === '' || $query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account" and "query" are required']],
                'isError' => true,
            ];
        }

        if (mb_strlen($query) < 3) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "query" must be at least 3 characters']],
                'isError' => true,
            ];
        }

        try {
            $results = $this->libredeskService->searchConversations($accountKey, $query);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($results),
                    'conversations' => $results,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
