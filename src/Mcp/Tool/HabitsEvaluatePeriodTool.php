<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsEvaluatePeriodTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_evaluate_period';
    }

    public function getDescription(): string
    {
        return 'Close a calendar day or ISO week for one user on one habit: compares logged increments/relapses to goal_mode, records period_win or period_miss once per period_key, and applies points_period_win / points_period_miss.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'habit_xuid' => ['type' => 'string'],
                'user_xuid' => ['type' => 'string'],
                'granularity' => ['type' => 'string', 'description' => 'day or week'],
                'period_anchor_date' => ['type' => 'string', 'description' => 'Y-m-d inside the period (server TZ)'],
            ],
            'required' => ['habit_xuid', 'user_xuid', 'granularity', 'period_anchor_date'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $out = $this->habitsService->evaluatePeriod(
                (string) $arguments['habit_xuid'],
                (string) $arguments['user_xuid'],
                (string) $arguments['granularity'],
                (string) $arguments['period_anchor_date'],
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode($out, JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
