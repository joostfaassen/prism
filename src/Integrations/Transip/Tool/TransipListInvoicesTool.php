<?php

namespace App\Integrations\Transip\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Transip\TransipService;

class TransipListInvoicesTool implements ToolInterface
{
    public function __construct(
        private readonly TransipService $transipService,
    ) {
    }

    public function getName(): string
    {
        return 'transip_list_invoices';
    }

    public function getDescription(): string
    {
        return 'List the invoices on a TransIP profile. Each invoice has an invoiceNumber, creationDate and totalAmount (in cents). This is the profile/billing information TransIP exposes via its API. Use transip_get_invoice for the full details of a single invoice.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'TransIP profile key. Optional when only one profile is configured.',
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
            $invoices = $this->transipService->listInvoices($arguments['profile'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($invoices),
                    'invoices' => $invoices,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing TransIP invoices: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
