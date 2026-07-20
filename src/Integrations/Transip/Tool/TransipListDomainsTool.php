<?php

namespace App\Integrations\Transip\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Transip\TransipService;

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
        return 'List all domain names in a TransIP profile, including registration/renewal dates, transfer lock and DNSSEC status, tags and overall status. Omit "profile" to use the only configured profile.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'TransIP profile key (see transip_list_profiles). Optional when only one profile is configured.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'transip';
    }

    public function execute(array $arguments): array
    {
        try {
            $domains = $this->transipService->listDomains($arguments['profile'] ?? null);

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
