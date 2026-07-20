<?php

namespace App\Integrations\Picnic\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Picnic\PicnicService;

class PicnicGetDeliveryTool implements ToolInterface
{
    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_get_delivery';
    }

    public function getDescription(): string
    {
        return 'Get full details of a single Picnic delivery by its ID, including the ordered items, slot, status and totals.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'delivery_id' => [
                    'type' => 'string',
                    'description' => 'The delivery ID as returned by picnic_list_deliveries',
                ],
                'profile' => [
                    'type' => 'string',
                    'description' => 'Picnic profile key. Defaults to the first configured profile.',
                ],
            ],
            'required' => ['delivery_id'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $deliveryId = trim((string) ($arguments['delivery_id'] ?? ''));
        $profile = $arguments['profile'] ?? null;

        if ($deliveryId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "delivery_id" is required']],
                'isError' => true,
            ];
        }

        try {
            $delivery = $this->picnicService->getDelivery($deliveryId, $profile);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $delivery,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Picnic delivery: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
