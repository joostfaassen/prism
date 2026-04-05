<?php

namespace App\Mcp\Tool;

use App\Calendar\CalendarService;

class CalendarGetEventTool implements ToolInterface
{
    public function __construct(
        private readonly CalendarService $calendarService,
    ) {
    }

    public function getName(): string
    {
        return 'calendar_get_event';
    }

    public function getDescription(): string
    {
        return 'Fetch a specific calendar event by its UID from a selected calendar';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calendar' => [
                    'type' => 'string',
                    'description' => 'Calendar key to search in (e.g. "jf-priv")',
                ],
                'uid' => [
                    'type' => 'string',
                    'description' => 'The UID of the event to retrieve',
                ],
            ],
            'required' => ['calendar', 'uid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'calendar';
    }

    public function execute(array $arguments): array
    {
        $calendarKey = $arguments['calendar'] ?? '';
        $uid = $arguments['uid'] ?? '';

        if ($calendarKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "calendar" is required.']],
                'isError' => true,
            ];
        }

        if ($uid === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "uid" is required.']],
                'isError' => true,
            ];
        }

        try {
            $event = $this->calendarService->getEvent($calendarKey, $uid);

            if ($event === null) {
                return [
                    'content' => [['type' => 'text', 'text' => sprintf('Event with UID "%s" not found in calendar "%s".', $uid, $calendarKey)]],
                    'isError' => true,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['event' => $event], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching event: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
