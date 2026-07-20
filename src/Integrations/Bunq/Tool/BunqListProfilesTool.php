<?php

namespace App\Integrations\Bunq\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Bunq\BunqService;

class BunqListProfilesTool implements ToolInterface
{
    public function __construct(
        private readonly BunqService $bunqService,
    ) {
    }

    public function getName(): string
    {
        return 'bunq_list_profiles';
    }

    public function getDescription(): string
    {
        return 'List all configured bunq bank profiles. Returns profile keys that can be used with other bunq tools. Optionally discovers monetary account IDs from the bunq API.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'discover' => [
                    'type' => 'boolean',
                    'description' => 'If true, also fetches monetary accounts from the bunq API to show IDs and balances. Default: false',
                ],
            ],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'bunq';
    }

    public function execute(array $arguments): array
    {
        try {
            $configured = $this->bunqService->listProfiles();
            $result = ['configured_profiles' => $configured];

            if ($arguments['discover'] ?? false) {
                $result['bunq_monetary_accounts'] = $this->bunqService->listMonetaryAccounts();
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing bunq profiles: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
