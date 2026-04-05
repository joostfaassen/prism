<?php

namespace App\Mcp\Tool;

use App\Calendar\CalendarService;

class CalendarListCalendarsTool implements ToolInterface
{
    public function __construct(
        private readonly CalendarService $calendarService,
    ) {
    }

    public function getName(): string
    {
        return 'calendar_list_calendars';
    }

    public function getDescription(): string
    {
        return 'List all configured calendars and their keys';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function getAccountType(): ?string
    {
        return 'calendar';
    }

    public function execute(array $arguments): array
    {
        try {
            $calendars = $this->calendarService->listCalendars();

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['calendars' => $calendars], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing calendars: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
