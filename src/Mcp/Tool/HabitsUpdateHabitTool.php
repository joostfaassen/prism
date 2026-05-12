<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsUpdateHabitTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_update_habit';
    }

    public function getDescription(): string
    {
        return 'Patch a habit by habit_xuid (same optional fields as create plus slug/title).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'habit_xuid' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'goal_mode' => ['type' => 'string'],
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
                'active' => ['type' => 'boolean'],
            ],
            'required' => ['habit_xuid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $habitXuid = (string) $arguments['habit_xuid'];
            $fields = $arguments;
            unset($fields['habit_xuid']);
            $row = $this->habitsService->updateHabit($habitXuid, $fields);

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
