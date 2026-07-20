<?php

namespace App\Integrations\Libredesk\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Libredesk\LibredeskService;

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
                'profile' => [
                    'type' => 'string',
                    'description' => 'Libredesk profile key',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query (minimum 3 characters)',
                ],
            ],
            'required' => ['profile', 'query'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        $query = $arguments['query'] ?? '';

        if ($profileKey === '' || $query === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "profile" and "query" are required']],
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
            $results = $this->libredeskService->searchConversations($profileKey, $query);

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
