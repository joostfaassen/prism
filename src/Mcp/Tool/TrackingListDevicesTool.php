<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Tracking\TrackingService;

class TrackingListDevicesTool implements ToolInterface
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'tracking_list_devices';
    }

    public function getDescription(): string
    {
        return 'List GPS tracking devices for this server (slug, label, map color, ingest format preset). Use slugs in the REST ingest URL.';
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
            $devices = $this->trackingService->listDevices($serverName);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'server' => $serverName,
                    'devices' => $devices,
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
