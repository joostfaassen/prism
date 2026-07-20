<?php

namespace App\Integrations\Freescout\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Freescout\FreescoutService;

class FreescoutListUsersTool implements ToolInterface
{
    public function __construct(
        private readonly FreescoutService $freescoutService,
    ) {
    }

    public function getName(): string
    {
        return 'freescout_list_users';
    }

    public function getDescription(): string
    {
        return 'List users (agents) in a Freescout instance. Returns user IDs, emails, names, and roles. Useful for resolving assignees or finding user IDs for thread creation.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Freescout profile key',
                ],
            ],
            'required' => ['profile'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'freescout';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        if ($profileKey === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "profile" is required']],
                'isError' => true,
            ];
        }

        try {
            $users = $this->freescoutService->listUsers($profileKey);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'count' => count($users),
                    'users' => $users,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
