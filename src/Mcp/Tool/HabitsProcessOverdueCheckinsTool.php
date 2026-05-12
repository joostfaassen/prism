<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsProcessOverdueCheckinsTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_process_overdue_check_ins';
    }

    public function getDescription(): string
    {
        return 'Apply missed penalties for this server: any open check-in past due becomes missed, logs checkin_missed, and applies points_missed_checkin. Same logic as console habits:process-check-ins for the active server only.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $n = $this->habitsService->processExpiredCheckIns();

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['closed_missed' => $n], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
