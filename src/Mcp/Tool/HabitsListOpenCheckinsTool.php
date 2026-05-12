<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsListOpenCheckinsTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_list_open_check_ins';
    }

    public function getDescription(): string
    {
        return 'List open (pending) check-in requests, optionally filtered by habit_xuid.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'habit_xuid' => ['type' => 'string'],
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
            $hx = isset($arguments['habit_xuid']) ? (string) $arguments['habit_xuid'] : null;
            $rows = $this->habitsService->listOpenCheckIns($hx);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['check_ins' => $rows], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
