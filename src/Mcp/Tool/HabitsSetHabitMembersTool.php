<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsSetHabitMembersTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_set_habit_members';
    }

    public function getDescription(): string
    {
        return 'Replace the member list of a habit with the given usernames (must exist). Enables competition/collaboration scoreboards for that habit.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'habit_xuid' => ['type' => 'string'],
                'usernames' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Full replacement list of usernames',
                ],
            ],
            'required' => ['habit_xuid', 'usernames'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $list = $arguments['usernames'] ?? [];
            if (!is_array($list)) {
                return [
                    'content' => [['type' => 'text', 'text' => 'usernames must be an array']],
                    'isError' => true,
                ];
            }
            $row = $this->habitsService->setHabitMembers((string) $arguments['habit_xuid'], $list);

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
