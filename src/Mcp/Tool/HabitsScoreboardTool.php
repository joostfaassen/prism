<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsScoreboardTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_scoreboard';
    }

    public function getDescription(): string
    {
        return 'Per-habit leaderboard for a calendar day or ISO week: ranked points from ledger plus increment totals for friendly competition or collaboration.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'habit_xuid' => ['type' => 'string'],
                'period' => ['type' => 'string', 'description' => 'day or week'],
                'anchor_date' => ['type' => 'string', 'description' => 'Y-m-d in server TZ; default today'],
            ],
            'required' => ['habit_xuid', 'period'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $out = $this->habitsService->scoreboard(
                (string) $arguments['habit_xuid'],
                (string) $arguments['period'],
                isset($arguments['anchor_date']) ? (string) $arguments['anchor_date'] : null,
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
