<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Tracking\TrackingConfigLoader;
use App\Tracking\TrackingService;

class TrackingGetTraceDayTool implements ToolInterface
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly TrackingConfigLoader $trackingConfigLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'tracking_get_trace_day';
    }

    public function getDescription(): string
    {
        return 'Return GPS sample points for one calendar day (timezone from the first tracking account on this server). Optionally filter by device xuids.';
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
                'device_xuids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'If omitted or null, include all devices. If empty array, return no points.',
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

            $deviceXuids = $arguments['device_xuids'] ?? null;
            if ($deviceXuids !== null && !is_array($deviceXuids)) {
                throw new \InvalidArgumentException('device_xuids must be an array of strings or omitted.');
            }
            /** @var list<string>|null $filtered */
            $filtered = $deviceXuids !== null
                ? array_values(array_filter(array_map('strval', $deviceXuids)))
                : null;

            $points = $this->trackingService->getTraceForDay($serverName, $date, $tz, $filtered);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'server' => $serverName,
                    'date' => $date,
                    'timezone' => $tz->getName(),
                    'point_count' => count($points),
                    'points' => $points,
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
