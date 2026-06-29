<?php

namespace App\Mcp\Tool;

use App\Transip\TransipService;

class TransipUpsertDnsRecordTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_upsert_dns_record';
    }

    public function getDescription(): string
    {
        return <<<'TXT'
Create or update a single DNS record on an existing TransIP domain. WRITE operation.

Matching is done on (name, type); the rest of the zone is never touched:
- no existing record with that name+type → the record is created
- exactly one existing record with that name+type → its content/TTL is updated
- multiple records share that name+type (e.g. several A or TXT records) → pass "replace_content" with the exact current content of the one you want to update

The domain must already exist in the account and use TransIP nameservers for changes to take effect.
TXT;
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
                'name' => [
                    'type' => 'string',
                    'description' => 'Record name relative to the domain. Use "@" for the apex, "www" for www.<domain>, "*" for a wildcard.',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'DNS record type.',
                    'enum' => TransipService::RECORD_TYPES,
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Record content/value, e.g. "127.0.0.1" for A, "10 mail.example.com." for MX, the target host for CNAME, or the quoted string for TXT.',
                ],
                'expire' => [
                    'type' => 'integer',
                    'description' => 'TTL in seconds. Defaults to 3600.',
                    'default' => 3600,
                ],
                'replace_content' => [
                    'type' => 'string',
                    'description' => 'Only needed when several records share the same name+type: the exact current content of the record to update.',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'TransIP account key. Optional when only one account is configured.',
                ],
            ],
            'required' => ['domain', 'name', 'type', 'content'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'transip';
    }

    public function execute(array $arguments): array
    {
        $domain = trim((string) ($arguments['domain'] ?? ''));
        $name = (string) ($arguments['name'] ?? '');
        $type = trim((string) ($arguments['type'] ?? ''));
        $content = (string) ($arguments['content'] ?? '');

        if ($domain === '' || $name === '' || $type === '' || $content === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "domain", "name", "type" and "content" are required']],
                'isError' => true,
            ];
        }

        $expire = isset($arguments['expire']) ? (int) $arguments['expire'] : 3600;
        if ($expire <= 0) {
            $expire = 3600;
        }

        try {
            $result = $this->transipService->upsertDnsEntry(
                domainName: $domain,
                name: $name,
                type: $type,
                content: $content,
                expire: $expire,
                replaceContent: isset($arguments['replace_content']) ? (string) $arguments['replace_content'] : null,
                accountKey: $arguments['account'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error upserting TransIP DNS record: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
