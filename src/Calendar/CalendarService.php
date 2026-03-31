<?php

namespace App\Calendar;

use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CalendarService
{
    public function __construct(
        private readonly CalendarConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, summary: string|null}>
     */
    public function listCalendars(): array
    {
        $result = [];

        foreach ($this->configLoader->getCalendars() as $key => $config) {
            $result[] = [
                'key' => $key,
                'summary' => $config->summary,
            ];
        }

        return $result;
    }

    /**
     * Fetch events for a date range across one or more calendars.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function listEvents(string $calendarsParam, string $dateFrom, string $dateTo): array
    {
        $keys = $this->configLoader->resolveCalendarKeys($calendarsParam);
        $from = new \DateTimeImmutable($dateFrom . 'T00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable($dateTo . 'T23:59:59', new \DateTimeZone('UTC'));

        $result = [];

        foreach ($keys as $key) {
            $calendar = $this->fetchCalendar($key);
            $result[$key] = $this->extractEvents($calendar, $from, $to);
            $calendar->destroy();
        }

        return $result;
    }

    /**
     * Fetch a single event by UID from a specific calendar.
     *
     * @return array<string, mixed>|null
     */
    public function getEvent(string $calendarKey, string $uid): ?array
    {
        $calendar = $this->fetchCalendar($calendarKey);

        try {
            foreach ($calendar->VEVENT ?? [] as $vevent) {
                /** @var VEvent $vevent */
                $eventUid = (string) ($vevent->UID ?? '');

                if ($eventUid === $uid) {
                    return $this->serializeEvent($vevent);
                }
            }

            return null;
        } finally {
            $calendar->destroy();
        }
    }

    private function fetchCalendar(string $key): VCalendar
    {
        $config = $this->configLoader->getCalendar($key);
        $response = $this->httpClient->request('GET', $config->icsUrl);
        $icsData = $response->getContent();

        return VObject\Reader::read($icsData, VObject\Reader::OPTION_FORGIVING);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractEvents(VCalendar $calendar, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $expanded = $calendar->expand($from, $to);
        $events = [];

        foreach ($expanded->VEVENT ?? [] as $vevent) {
            /** @var VEvent $vevent */
            $events[] = $this->serializeEvent($vevent);
        }

        usort($events, fn(array $a, array $b) => ($a['start'] ?? '') <=> ($b['start'] ?? ''));

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEvent(VEvent $vevent): array
    {
        $event = [
            'uid' => (string) ($vevent->UID ?? ''),
            'summary' => (string) ($vevent->SUMMARY ?? ''),
            'start' => $vevent->DTSTART ? $vevent->DTSTART->getDateTime()->format('c') : null,
            'end' => $vevent->DTEND ? $vevent->DTEND->getDateTime()->format('c') : null,
            'location' => (string) ($vevent->LOCATION ?? '') ?: null,
            'description' => (string) ($vevent->DESCRIPTION ?? '') ?: null,
            'status' => (string) ($vevent->STATUS ?? '') ?: null,
        ];

        if ($vevent->ORGANIZER) {
            $event['organizer'] = (string) $vevent->ORGANIZER;
        }

        $attendees = [];
        foreach ($vevent->ATTENDEE ?? [] as $attendee) {
            $attendees[] = (string) $attendee;
        }
        if ($attendees) {
            $event['attendees'] = $attendees;
        }

        if ($vevent->RRULE) {
            $event['recurrence_rule'] = (string) $vevent->RRULE;
        }

        return $event;
    }
}
