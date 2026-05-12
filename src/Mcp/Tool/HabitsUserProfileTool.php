<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsUserProfileTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_user_profile';
    }

    public function getDescription(): string
    {
        return 'Habit profile for one user: point balance across ledger, memberships, recent events.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_xuid' => ['type' => 'string'],
            ],
            'required' => ['user_xuid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $out = $this->habitsService->userProfile((string) $arguments['user_xuid']);

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
