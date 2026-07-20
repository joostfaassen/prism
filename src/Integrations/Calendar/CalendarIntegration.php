<?php

namespace App\Integrations\Calendar;

use App\Integrations\IntegrationInterface;

class CalendarIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'calendar';
    }

    public function getLabel(): string
    {
        return 'Calendar';
    }

    public function getDescription(): string
    {
        return 'ICS calendars — list calendars and events, read event details.';
    }
}
