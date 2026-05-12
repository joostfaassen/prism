<?php

namespace App\Mcp\Tool;

use App\Picnic\PicnicService;

class PicnicListDeliveriesTool implements ToolInterface
{
    private const ALLOWED_STATES = ['CURRENT', 'COMPLETED', 'CANCELLED'];

    public function __construct(
        private readonly PicnicService $picnicService,
    ) {
    }

    public function getName(): string
    {
        return 'picnic_list_deliveries';
    }

    public function getDescription(): string
    {
        return 'List Picnic deliveries (past and upcoming). Optionally filter by state: CURRENT, COMPLETED, CANCELLED. Omit the filter to see all.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'states' => [
                    'type' => 'array',
                    'description' => 'Optional list of states to filter on. Allowed values: CURRENT, COMPLETED, CANCELLED.',
                    'items' => [
                        'type' => 'string',
                        'enum' => self::ALLOWED_STATES,
                    ],
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Picnic account key. Defaults to the first configured account.',
                ],
            ],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'picnic';
    }

    public function execute(array $arguments): array
    {
        $account = $arguments['account'] ?? null;
        $states = $arguments['states'] ?? [];

        if (!is_array($states)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "states" must be an array of strings']],
                'isError' => true,
            ];
        }

        $invalid = array_diff($states, self::ALLOWED_STATES);
        if ($invalid !== []) {
            return [
                'content' => [['type' => 'text', 'text' => sprintf(
                    'Invalid state(s): %s. Allowed: %s',
                    implode(', ', $invalid),
                    implode(', ', self::ALLOWED_STATES),
                )]],
                'isError' => true,
            ];
        }

        try {
            $deliveries = $this->picnicService->listDeliveries($states, $account);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'states' => $states,
                    'count' => count($deliveries),
                    'deliveries' => $deliveries,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing Picnic deliveries: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
