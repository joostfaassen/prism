<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Tracking\TrackingService;

class TrackingZoneEventsQueryTool implements ToolInterface
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'tracking_zone_events_query';
    }

    public function getDescription(): string
    {
        return 'Query zone enter/exit events for one geofence (zone xuid) between two ISO 8601 timestamps. Optionally filter by device xuid.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'zone_xuid' => ['type' => 'string', 'description' => 'Zone location xuid from tracking_list_zones'],
                'from_iso' => ['type' => 'string', 'description' => 'Range start (parseable by PHP DateTimeImmutable, e.g. 2026-04-01T00:00:00)'],
                'to_iso' => ['type' => 'string', 'description' => 'Range end inclusive'],
                'device_xuid' => ['type' => 'string', 'description' => 'Optional device xuid to filter'],
            ],
            'required' => ['zone_xuid', 'from_iso', 'to_iso'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'tracking';
    }

    public function execute(array $arguments): array
    {
        try {
            $zoneXuid = trim((string) ($arguments['zone_xuid'] ?? ''));
            $from = trim((string) ($arguments['from_iso'] ?? ''));
            $to = trim((string) ($arguments['to_iso'] ?? ''));
            $deviceXuid = isset($arguments['device_xuid']) ? trim((string) $arguments['device_xuid']) : null;
            if ($deviceXuid === '') {
                $deviceXuid = null;
            }

            if ($zoneXuid === '' || $from === '' || $to === '') {
                throw new \InvalidArgumentException('zone_xuid, from_iso, and to_iso are required.');
            }

            $serverName = $this->serverContext->getServerName();
            $events = $this->trackingService->queryZoneEvents($serverName, $zoneXuid, $from, $to, $deviceXuid);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'server' => $serverName,
                    'count' => count($events),
                    'events' => $events,
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
