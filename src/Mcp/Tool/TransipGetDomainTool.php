<?php

namespace App\Mcp\Tool;

use App\Transip\TransipService;

class TransipGetDomainTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_get_domain';
    }

    public function getDescription(): string
    {
        return 'Get the settings of a single TransIP domain: registration and renewal dates, transfer lock, DNSSEC, auth code, tags, status, plus its nameservers and WHOIS contacts. Use transip_get_dns_records for the DNS zone.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => [
                    'type' => 'string',
                    'description' => 'The domain name to inspect, e.g. "example.com".',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'TransIP account key. Optional when only one account is configured.',
                ],
            ],
            'required' => ['domain'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'transip';
    }

    public function execute(array $arguments): array
    {
        $domain = trim((string) ($arguments['domain'] ?? ''));
        if ($domain === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "domain" is required']],
                'isError' => true,
            ];
        }

        try {
            $info = $this->transipService->getDomain($domain, $arguments['account'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $info,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching TransIP domain: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
