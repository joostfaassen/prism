<?php

namespace App\Mcp\Tool;

use App\Twilio\TwilioService;

class TwilioListAccountsTool implements ToolInterface
{
    public function __construct(
        private readonly TwilioService $twilioService,
    ) {
    }

    public function getName(): string
    {
        return 'twilio_list_accounts';
    }

    public function getDescription(): string
    {
        return 'List all configured Twilio accounts. Returns account keys that can be used with other Twilio tools.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function getAccountType(): ?string
    {
        return 'twilio';
    }

    public function execute(array $arguments): array
    {
        try {
            $accounts = $this->twilioService->listAccounts();

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'accounts' => $accounts,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
