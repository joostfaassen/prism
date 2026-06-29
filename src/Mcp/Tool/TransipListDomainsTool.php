<?php

namespace App\Mcp\Tool;

use App\Transip\TransipService;

class TransipListDomainsTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_list_domains';
    }

    public function getDescription(): string
    {
        return 'List all domain names in a TransIP account, including registration/renewal dates, transfer lock and DNSSEC status, tags and overall status. Omit "account" to use the only configured account.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'TransIP account key (see transip_list_accounts). Optional when only one account is configured.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'transip';
    }

    public function execute(array $arguments): array
    {
        try {
            $domains = $this->transipService->listDomains($arguments['account'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($domains),
                    'domains' => $domains,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing TransIP domains: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
