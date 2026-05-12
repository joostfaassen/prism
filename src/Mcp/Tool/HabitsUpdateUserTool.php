<?php

namespace App\Mcp\Tool;

use App\Habits\HabitsService;

class HabitsUpdateUserTool implements ToolInterface
{
    public function __construct(
        private readonly HabitsService $habitsService,
    ) {
    }

    public function getName(): string
    {
        return 'habits_update_user';
    }

    public function getDescription(): string
    {
        return 'Patch a habit user by xuid: display_name, config_yaml, active.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_xuid' => ['type' => 'string'],
                'display_name' => ['type' => 'string'],
                'config_yaml' => ['type' => 'string', 'description' => 'Set or clear (empty string clears)'],
                'active' => ['type' => 'boolean'],
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
            $patch = [];
            if (isset($arguments['display_name'])) {
                $patch['display_name'] = (string) $arguments['display_name'];
            }
            if (array_key_exists('config_yaml', $arguments)) {
                $patch['config_yaml'] = (string) $arguments['config_yaml'];
            }
            if (isset($arguments['active'])) {
                $patch['active'] = (bool) $arguments['active'];
            }
            $row = $this->habitsService->updateUser((string) $arguments['user_xuid'], $patch);

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
