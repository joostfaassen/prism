<?php

namespace App\Mcp\Tool;

use App\Calendar\CalendarService;

class CalendarListEventsTool implements ToolInterface
{
    public function __construct(
        private readonly CalendarService $calendarService,
    ) {
    }

    public function getName(): string
    {
        return 'calendar_list_events';
    }

    public function getDescription(): string
    {
        return 'Fetch calendar events for a date range. '
            . 'Use calendar key (e.g. "jf-priv"), comma-separated keys (e.g. "jf-priv,jf-work"), '
            . 'or "*" for all configured calendars.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendars' => [
                    'type' => 'string',
                    'description' => 'Calendar key, comma-separated keys, or "*" for all calendars',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Start date (inclusive), ISO 8601 format (YYYY-MM-DD)',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'End date (inclusive), ISO 8601 format (YYYY-MM-DD)',
                ],
            ],
            'required' => ['calendars', 'date_from', 'date_to'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'calendar';
    }

    public function execute(array $arguments): array
    {
        $calendars = $arguments['calendars'] ?? '';
        $dateFrom = $arguments['date_from'] ?? '';
        $dateTo = $arguments['date_to'] ?? '';

        if ($calendars === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "calendars" is required. Use a calendar key, comma-separated keys, or "*" for all.']],
                'isError' => true,
            ];
        }

        if ($dateFrom === '' || $dateTo === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "date_from" and "date_to" are required (YYYY-MM-DD).']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->calendarService->listEvents($calendars, $dateFrom, $dateTo);

            $totalCount = 0;
            foreach ($result as $events) {
                $totalCount += count($events);
            }

            $output = [
                'total_events' => $totalCount,
                'calendars' => $result,
            ];

            return [
                'content' => [['type' => 'text', 'text' => json_encode($output, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching events: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
