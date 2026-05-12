<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsRequestCheckinTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_request_check_in';
    }

    public function getDescription(): string
    {
        return 'Create accountability check-ins for listed users on a habit (checkins_enabled required). Sends agent_notify to the server chat agent with structured context; if not fulfilled by due_at, run habits_process_overdue_check_ins or cron to treat as missed (failed run) and apply points_missed_checkin.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'habit_xuid' => ['type' => 'string'],
                'user_xuids' => ['type' => 'array', 'items' => ['type' => 'string']],
                'message' => ['type' => 'string', 'description' => 'Prompt shown to the participant via the agent'],
                'grace_hours' => ['type' => 'integer', 'description' => 'Override habit checkin_grace_hours'],
            ],
            'required' => ['habit_xuid', 'user_xuids', 'message'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $uids = $arguments['user_xuids'] ?? [];
            if (!is_array($uids)) {
                return [
                    'content' => [['type' => 'text', 'text' => 'user_xuids must be an array']],
                    'isError' => true,
                ];
            }
            $out = $this->habitsService->requestCheckIn(
                (string) $arguments['habit_xuid'],
                array_map('strval', $uids),
                (string) $arguments['message'],
                isset($arguments['grace_hours']) ? (int) $arguments['grace_hours'] : null,
                'mcp',
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['results' => $out], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
