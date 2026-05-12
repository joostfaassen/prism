<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsListHabitsTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_list_habits';
    }

    public function getDescription(): string
    {
        return 'List habits with goal modes (daily_total, weekly_total, weekly_sessions, abstain), scoring fields, members, and xuids. Goal types support hydration totals, N×/week exercise, and break/de-learning habits with relapse scoring.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => ['type' => 'boolean'],
            ],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $rows = $this->habitsService->listHabits((bool) ($arguments['include_inactive'] ?? false));

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['habits' => $rows], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
