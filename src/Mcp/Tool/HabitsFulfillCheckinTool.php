<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsFulfillCheckinTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_fulfill_check_in';
    }

    public function getDescription(): string
    {
        return 'Mark an open check-in as fulfilled when the participant replied; records checkin_ack and optional points_checkin_ack.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'check_in_xuid' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['check_in_xuid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $out = $this->habitsService->fulfillCheckIn(
                (string) $arguments['check_in_xuid'],
                isset($arguments['note']) ? (string) $arguments['note'] : null,
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
