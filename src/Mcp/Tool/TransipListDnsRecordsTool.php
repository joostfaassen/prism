<?php

namespace App\Mcp\Tool;

use App\Transip\TransipService;

class TransipListDnsRecordsTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_get_dns_records';
    }

    public function getDescription(): string
    {
        return 'List all DNS records of a TransIP domain. Each record has name (e.g. "@", "www"), expire (TTL in seconds), type (A, AAAA, CNAME, MX, NS, TXT, SRV, SSHFP, TLSA, CAA, NAPTR) and content. DNS changes only take effect when the domain uses TransIP nameservers.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => [
                    'type' => 'string',
                    'description' => 'The domain name, e.g. "example.com".',
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
            $entries = $this->transipService->getDnsEntries($domain, $arguments['account'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'domain' => rtrim($domain, '.'),
                    'count' => count($entries),
                    'dnsEntries' => $entries,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching TransIP DNS records: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
