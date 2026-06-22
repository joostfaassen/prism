<?php

namespace App\Mcp\Tool;

use App\SendGrid\SendGridService;

class SendGridListSingleSendsTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_list_single_sends';
    }

    public function getDescription(): string
    {
        return 'List SendGrid Marketing Campaigns Single Sends (one-off newsletters/campaigns) with their id, name and status. Use this to find the single_send_id of a campaign before fetching its detailed stats with sendgrid_get_single_send_stats.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'SendGrid account key. Optional if only one account is configured.',
                ],
                'page_size' => [
                    'type' => 'integer',
                    'description' => 'Number of Single Sends to return per page (max 100).',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'sendgrid';
    }

    public function execute(array $arguments): array
    {
        try {
            $result = $this->sendGridService->listSingleSends(
                accountKey: $arguments['account'] ?? null,
                pageSize: isset($arguments['page_size']) ? (int) $arguments['page_size'] : null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'result' => $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing SendGrid single sends: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
