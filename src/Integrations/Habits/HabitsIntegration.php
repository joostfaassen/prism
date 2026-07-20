<?php

namespace App\Integrations\Habits;

use App\Integrations\IntegrationInterface;

class HabitsIntegration implements IntegrationInterface
{
    public function getType(): string
    {
        return 'habits';
    }

    public function getLabel(): string
    {
        return 'Habits';
    }

    public function getDescription(): string
    {
        return 'Habit tracking — users, habits, check-ins, events and scoreboards (database-backed).';
    }
}
