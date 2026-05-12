<?php

namespace App\Mcp\Tool;

use App\Slack\SlackService;

class SlackListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly SlackService $slackService,
    ) {
    }

    public function getName(): string
    {
        return 'slack_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List configured Slack workspace accounts. Returns account keys and labels. Use the account key in other Slack tools to specify which workspace to interact with.';
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
        return 'slack';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->slackService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($accounts),
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Slack accounts: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
