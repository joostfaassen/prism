<?php

namespace App\Mcp\Tool;

use App\Ga4\Ga4Service;

class Ga4GetMetadataTool implements ToolInterface
{
    public function __construct(
        private readonly Ga4Service $ga4Service,
    ) {
    }

    public function getName(): string
    {
        return 'ga4_get_metadata';
    }

    public function getDescription(): string
    {
        return 'List the dimensions and metrics available for a GA4 property. Use this to discover valid API field names (e.g. "date", "country", "activeUsers", "sessions") before building a ga4_run_report query.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'GA4 account key. Optional if only one account is configured.',
                ],
                'property_id' => [
                    'type' => 'string',
                    'description' => 'GA4 property id (numeric, e.g. "365738680"). Optional if a default property_id is configured for the account.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'ga4';
    }

    public function execute(array $arguments): array
    {
        try {
            $metadata = $this->ga4Service->getMetadata(
                accountKey: $arguments['account'] ?? null,
                propertyId: isset($arguments['property_id']) ? (string) $arguments['property_id'] : null,
            );

            $dimensions = array_map(
                static fn(array $d): array => [
                    'apiName' => $d['apiName'] ?? null,
                    'uiName' => $d['uiName'] ?? null,
                    'description' => $d['description'] ?? null,
                ],
                array_values(array_filter($metadata['dimensions'] ?? [], 'is_array')),
            );
            $metrics = array_map(
                static fn(array $m): array => [
                    'apiName' => $m['apiName'] ?? null,
                    'uiName' => $m['uiName'] ?? null,
                    'type' => $m['type'] ?? null,
                    'description' => $m['description'] ?? null,
                ],
                array_values(array_filter($metadata['metrics'] ?? [], 'is_array')),
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'dimensions' => $dimensions,
                    'metrics' => $metrics,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching GA4 metadata: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
