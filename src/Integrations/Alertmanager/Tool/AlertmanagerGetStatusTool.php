<?php

namespace App\Integrations\Alertmanager\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Alertmanager\AlertmanagerService;

class AlertmanagerGetStatusTool implements ToolInterface
{
    public function __construct(
        private readonly AlertmanagerService $alertmanagerService,
    ) {
    }

    public function getName(): string
    {
        return 'alertmanager_get_status';
    }

    public function getDescription(): string
    {
        return 'Get Alertmanager runtime status: version info, uptime, and cluster peer state. '
            . 'Useful for a quick health check of the Alertmanager instance or cluster.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Alertmanager profile key. Optional if only one profile is configured.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'alertmanager';
    }

    public function execute(array $arguments): array
    {
        try {
            $status = $this->alertmanagerService->getStatus($arguments['profile'] ?? null);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $status,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting Alertmanager status: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
