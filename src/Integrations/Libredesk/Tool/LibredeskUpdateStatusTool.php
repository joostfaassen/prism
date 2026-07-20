<?php

namespace App\Integrations\Libredesk\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Libredesk\LibredeskService;

class LibredeskUpdateStatusTool implements ToolInterface
{
    public function __construct(
        private readonly LibredeskService $libredeskService,
    ) {
    }

    public function getName(): string
    {
        return 'libredesk_update_status';
    }

    public function getDescription(): string
    {
        return <<<'DESC'
Update the status of a Libredesk conversation (identified by UUID).

Common statuses: "Open", "Resolved", "Closed", "Snoozed".
When setting status to "Snoozed", provide snoozed_until (e.g. "1h", "3h", "100h").
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'Libredesk profile key',
                ],
                'uuid' => [
                    'type' => 'string',
                    'description' => 'Conversation UUID',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'New status, e.g. Open, Resolved, Closed, Snoozed',
                ],
                'snoozed_until' => [
                    'type' => 'string',
                    'description' => 'Snooze duration (e.g. "1h", "3h", "100h"). Required when status is "Snoozed".',
                ],
            ],
            'required' => ['profile', 'uuid', 'status'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        $profileKey = $arguments['profile'] ?? '';
        $uuid = $arguments['uuid'] ?? '';
        $status = $arguments['status'] ?? '';

        if ($profileKey === '' || $uuid === '' || $status === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "profile", "uuid", and "status" are required']],
                'isError' => true,
            ];
        }

        $snoozedUntil = $arguments['snoozed_until'] ?? null;

        try {
            $result = $this->libredeskService->updateStatus($profileKey, $uuid, $status, $snoozedUntil);

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    ['success' => true, 'result' => $result],
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
