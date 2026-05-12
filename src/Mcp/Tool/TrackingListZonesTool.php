<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Tracking\TrackingService;

class TrackingListZonesTool implements ToolInterface
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'tracking_list_zones';
    }

    public function getDescription(): string
    {
        return 'List geofence locations (zones) for this server: center coordinates, radius in meters, and xuid for event queries.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'tracking';
    }

    public function execute(array $arguments): array
    {
        try {
            $serverName = $this->serverContext->getServerName();
            $zones = $this->trackingService->listZones($serverName);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'server' => $serverName,
                    'zones' => $zones,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
