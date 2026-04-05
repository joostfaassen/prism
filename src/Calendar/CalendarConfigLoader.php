<?php

namespace App\Calendar;

use App\Config\PrismConfigLoader;
use App\Config\ServerContext;

class CalendarConfigLoader
{
    public function __construct(
        private readonly PrismConfigLoader $configLoader,
        private readonly ServerContext $serverContext,
    ) {
    }

    /**
     * @return array<string, CalendarConfig>
     */
    public function getCalendars(): array
    {
        $raw = $this->configLoader->getAccountsByTypeForServer('calendar', $this->serverContext);
        $calendars = [];

        foreach ($raw as $key => $cfg) {
            $calendars[$key] = new CalendarConfig(
                key: $key,
                icsUrl: $cfg['ics_url'],
                summary: $cfg['summary'] ?? null,
            );
        }

        return $calendars;
    }

    public function getCalendar(string $key): CalendarConfig
    {
        $calendars = $this->getCalendars();

        if (!isset($calendars[$key])) {
            $available = implode(', ', array_keys($calendars));
            throw new \InvalidArgumentException(sprintf(
                'Unknown calendar: "%s". Available: %s',
                $key,
                $available,
            ));
        }

        return $calendars[$key];
    }

    /**
     * @return list<string>
     */
    public function resolveCalendarKeys(string $calendarsParam): array
    {
        if ($calendarsParam === '*') {
            return array_keys($this->getCalendars());
        }

        $keys = array_map('trim', explode(',', $calendarsParam));
        $keys = array_filter($keys, fn(string $k) => $k !== '');

        foreach ($keys as $key) {
            $this->getCalendar($key);
        }

        return array_values($keys);
    }
}
