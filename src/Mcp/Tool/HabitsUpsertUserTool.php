<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsUpsertUserTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_upsert_user';
    }

    public function getDescription(): string
    {
        return 'Create or update a habit user by username (URL-safe handle). Stores display name and optional per-user YAML (cues, identity, friction notes).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'username' => ['type' => 'string', 'description' => 'Unique handle per server, e.g. joe'],
                'display_name' => ['type' => 'string', 'description' => 'Human-readable name'],
                'config_yaml' => ['type' => 'string', 'description' => 'Optional YAML blob for coaching context'],
            ],
            'required' => ['username', 'display_name'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'habits';
    }

    public function execute(array $arguments): array
    {
        try {
            $row = $this->habitsService->upsertUserByUsername(
                (string) $arguments['username'],
                (string) $arguments['display_name'],
                isset($arguments['config_yaml']) ? (string) $arguments['config_yaml'] : null,
            );

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
