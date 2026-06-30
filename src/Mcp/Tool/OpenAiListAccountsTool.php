<?php

namespace App\Mcp\Tool;

use App\OpenAi\OpenAiService;

class OpenAiListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly OpenAiService $openAiService,
    ) {
    }

    public function getName(): string
    {
        return 'openai_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured OpenAI-compatible accounts. Returns account keys, labels, base URLs, and default models. Use the account key in other openai tools to choose which endpoint to use. If only one account is configured, the account argument can be omitted elsewhere.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'openai';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->openAiService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing OpenAI accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
