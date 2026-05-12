<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Tracking\TrackingConfigLoader;
use App\Tracking\TrackingService;

class TrackingDayZoneEventsTool implements ToolInterface
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly TrackingConfigLoader $trackingConfigLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'tracking_day_zone_events';
    }

    public function getDescription(): string
    {
        return 'Return all zone check-in and check-out events for every device on one calendar day (midnight-to-midnight in the tracking account timezone).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Calendar date YYYY-MM-DD',
                ],
            ],
            'required' => ['date'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'tracking';
    }

    public function execute(array $arguments): array
    {
        try {
            $date = trim((string) ($arguments['date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new \InvalidArgumentException('Argument "date" must be YYYY-MM-DD.');
            }

            $tz = $this->trackingConfigLoader->getTimezone();
            $serverName = $this->serverContext->getServerName();
            $events = $this->trackingService->queryAllZoneEventsForDay($serverName, $date, $tz);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'server' => $serverName,
                    'date' => $date,
                    'timezone' => $tz->getName(),
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
