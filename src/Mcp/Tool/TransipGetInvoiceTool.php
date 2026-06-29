<?php

namespace App\Mcp\Tool;

use App\Transip\TransipService;

class TransipGetInvoiceTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_get_invoice';
    }

    public function getDescription(): string
    {
        return 'Get a single TransIP invoice by its invoice number, including status, currency and amounts (in cents). Set with_items=true to also include the individual invoice line items. Amounts are excl. VAT unless the field name says InclVat.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_number' => [
                    'type' => 'string',
                    'description' => 'The invoice number, e.g. "F0000.1911.0000.0004" (see transip_list_invoices).',
                ],
                'with_items' => [
                    'type' => 'boolean',
                    'description' => 'Include the invoice line items. Defaults to false.',
                    'default' => false,
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'TransIP account key. Optional when only one account is configured.',
                ],
            ],
            'required' => ['invoice_number'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'transip';
    }

    public function execute(array $arguments): array
    {
        $invoiceNumber = trim((string) ($arguments['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "invoice_number" is required']],
                'isError' => true,
            ];
        }

        try {
            $invoice = $this->transipService->getInvoice(
                $invoiceNumber,
                (bool) ($arguments['with_items'] ?? false),
                $arguments['account'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $invoice,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching TransIP invoice: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
