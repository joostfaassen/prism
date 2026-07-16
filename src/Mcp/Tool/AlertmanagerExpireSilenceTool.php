<?php

namespace App\Mcp\Tool;

use App\Alertmanager\AlertmanagerService;

class AlertmanagerExpireSilenceTool implements ToolInterface
{
    public function __construct(
        private readonly AlertmanagerService $alertmanagerService,
    ) {
    }

    public function getName(): string
    {
        return 'alertmanager_expire_silence';
    }

    public function getDescription(): string
    {
        return 'Expire (delete) an existing Alertmanager silence by its silenceID, immediately un-muting the '
            . 'matched alerts. This is a WRITE action — only use it when explicitly intended. Find silence '
            . 'IDs with alertmanager_list_silences.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Alertmanager account key. Optional if only one account is configured.',
                ],
                'silence_id' => [
                    'type' => 'string',
                    'description' => 'The ID of the silence to expire (from alertmanager_list_silences).',
                ],
            ],
            'required' => ['silence_id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'alertmanager';
    }

    public function execute(array $arguments): array
    {
        $silenceId = trim((string) ($arguments['silence_id'] ?? ''));
        if ($silenceId === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "silence_id" argument is required.']],
                'isError' => true,
            ];
        }

        try {
            $this->alertmanagerService->expireSilence($arguments['account'] ?? null, $silenceId);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'expired' => true,
                    'silenceID' => $silenceId,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error expiring Alertmanager silence: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
