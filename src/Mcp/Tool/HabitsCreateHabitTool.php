<?php

namespace App\Mcp\Tool;

use App\Habits\Entity\Habit;
use App\Habits\HabitsService;

class HabitsCreateHabitTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_create_habit';
    }

    public function getDescription(): string
    {
        return 'Create a habit: slug, title, goal_mode (daily_total, weekly_total, weekly_sessions, abstain), optional targets, methodology YAML, and scoring (per-unit, period win/miss, missed check-in, relapse, check-in ack).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'goal_mode' => [
                    'type' => 'string',
                    'description' => 'One of: ' . implode(', ', Habit::GOAL_MODES),
                ],
                'description' => ['type' => 'string'],
                'unit' => ['type' => 'string'],
                'period_target' => ['type' => 'number'],
                'period_sessions' => ['type' => 'integer'],
                'config_yaml' => ['type' => 'string'],
                'points_per_unit' => ['type' => 'integer'],
                'points_period_win' => ['type' => 'integer'],
                'points_period_miss' => ['type' => 'integer'],
                'points_missed_checkin' => ['type' => 'integer'],
                'points_relapse' => ['type' => 'integer'],
                'points_checkin_ack' => ['type' => 'integer'],
                'checkins_enabled' => ['type' => 'boolean'],
                'checkin_grace_hours' => ['type' => 'integer'],
            ],
            'required' => ['slug', 'title', 'goal_mode'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $fields = $arguments;
            unset($fields['slug'], $fields['title'], $fields['goal_mode']);
            $row = $this->habitsService->createHabit(
                (string) $arguments['slug'],
                (string) $arguments['title'],
                (string) $arguments['goal_mode'],
                $fields,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($row, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
