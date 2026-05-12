<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsListUsersTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_list_users';
    }

    public function getDescription(): string
    {
        return 'List habit participants for this server (username, display name, config YAML, xuid). Use xuids in other habit tools.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => [
                    'type' => 'boolean',
                    'description' => 'Include inactive users (default false)',
                ],
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
            $inc = (bool) ($arguments['include_inactive'] ?? false);
            $rows = $this->habitsService->listUsers($inc);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['users' => $rows], JSON_THROW_ON_ERROR)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
