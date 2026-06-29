<?php

namespace App\Mcp\Tool;

use App\Transip\TransipService;

class TransipDeleteDnsRecordTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_delete_dns_record';
    }

    public function getDescription(): string
    {
        return <<<'TXT'
Delete a single DNS record from an existing TransIP domain. WRITE operation.

The record is selected by (name, type). If several records share that name+type,
narrow the selection by also passing "content" (and optionally "expire"); if the
selection is still ambiguous the tool refuses and lists the candidates instead of
deleting the wrong record.
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
                    'description' => 'Record name relative to the domain, e.g. "@", "www", "*".',
                ],
                'type' => [
                    'type' => 'string',
                    'description' => 'DNS record type.',
                    'enum' => TransipService::RECORD_TYPES,
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Optional. Exact content of the record, used to disambiguate when multiple records share the same name+type.',
                ],
                'expire' => [
                    'type' => 'integer',
                    'description' => 'Optional. TTL in seconds, used to further disambiguate.',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'TransIP account key. Optional when only one account is configured.',
                ],
            ],
            'required' => ['domain', 'name', 'type'],
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

        if ($domain === '' || $name === '' || $type === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "domain", "name" and "type" are required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->transipService->deleteDnsEntry(
                domainName: $domain,
                name: $name,
                type: $type,
                content: isset($arguments['content']) ? (string) $arguments['content'] : null,
                expire: isset($arguments['expire']) ? (int) $arguments['expire'] : null,
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
                'content' => [['type' => 'text', 'text' => 'Error deleting TransIP DNS record: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
