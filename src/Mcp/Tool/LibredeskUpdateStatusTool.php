<?php

namespace App\Mcp\Tool;

use App\Libredesk\LibredeskService;

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
                'account' => [
                    'type' => 'string',
                    'description' => 'Libredesk account key',
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
            'required' => ['account', 'uuid', 'status'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'libredesk';
    }

    public function execute(array $arguments): array
    {
        $accountKey = $arguments['account'] ?? '';
        $uuid = $arguments['uuid'] ?? '';
        $status = $arguments['status'] ?? '';

        if ($accountKey === '' || $uuid === '' || $status === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameters "account", "uuid", and "status" are required']],
                'isError' => true,
            ];
        }

        $snoozedUntil = $arguments['snoozed_until'] ?? null;

        try {
            $result = $this->libredeskService->updateStatus($accountKey, $uuid, $status, $snoozedUntil);

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
