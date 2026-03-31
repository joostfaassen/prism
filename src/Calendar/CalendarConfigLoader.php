<?php

namespace App\Calendar;

use Symfony\Component\Yaml\Yaml;

class CalendarConfigLoader
{
    /** @var array<string, CalendarConfig>|null */
    private ?array $calendars = null;

    public function __construct(
        private readonly string $configPath,
    ) {
    }

    /**
     * @return array<string, CalendarConfig>
     */
    public function getCalendars(): array
    {
        $this->load();

        return $this->calendars;
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
     * Resolve a calendars parameter to a list of calendar keys.
     * Accepts: "*" for all, "key1,key2" for CSV, or a single key.
     *
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

    private function load(): void
    {
        if ($this->calendars !== null) {
            return;
        }

        if (!file_exists($this->configPath)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $this->configPath));
        }

        $config = Yaml::parseFile($this->configPath);
        $this->calendars = [];

        foreach (($config['calendars'] ?? []) as $key => $cfg) {
            $this->calendars[$key] = new CalendarConfig(
                key: $key,
                icsUrl: $cfg['ics_url'],
                summary: $cfg['summary'] ?? null,
            );
        }
    }
}
