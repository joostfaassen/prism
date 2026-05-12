<?php

namespace App\Mcp\Tool;

use App\Habits\Entity\HabitEvent;
use App\Habits\HabitsService;

class HabitsRecordEventTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_record_event';
    }

    public function getDescription(): string
    {
        return 'Record a habit event for a member: increment (e.g. one glass of water), relapse (abstain/de-learning), checkin_ack, period_win/miss (usually via habits_evaluate_period), checkin_missed (system). Awards points from habit configuration.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'habit_xuid' => ['type' => 'string'],
                'user_xuid' => ['type' => 'string'],
                'kind' => [
                    'type' => 'string',
                    'description' => implode(', ', HabitEvent::KINDS),
                ],
                'quantity' => ['type' => 'number', 'description' => 'Default 1; multiplied by points_per_unit for increment'],
                'note' => ['type' => 'string'],
                'occurred_at' => ['type' => 'string', 'description' => 'ISO8601 in server habit timezone'],
                'metadata' => ['type' => 'object'],
            ],
            'required' => ['habit_xuid', 'user_xuid', 'kind'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $meta = $arguments['metadata'] ?? null;
            if ($meta !== null && !is_array($meta)) {
                return [
                    'content' => [['type' => 'text', 'text' => 'metadata must be an object']],
                    'isError' => true,
                ];
            }
            $out = $this->habitsService->recordEvent(
                (string) $arguments['habit_xuid'],
                (string) $arguments['user_xuid'],
                (string) $arguments['kind'],
                isset($arguments['quantity']) ? (float) $arguments['quantity'] : 1.0,
                isset($arguments['note']) ? (string) $arguments['note'] : null,
                isset($arguments['occurred_at']) ? (string) $arguments['occurred_at'] : null,
                $meta,
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
