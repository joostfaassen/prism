<?php

namespace App\Integrations\Alertmanager\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Alertmanager\AlertmanagerService;

class AlertmanagerCreateSilenceTool implements ToolInterface
{
    public function __construct(
        private readonly AlertmanagerService $alertmanagerService,
    ) {
    }

    public function getName(): string
    {
        return 'alertmanager_create_silence';
    }

    public function getDescription(): string
    {
        return 'Create a silence in Alertmanager to mute alerts matching the given label matchers for a '
            . 'period of time (e.g. during maintenance). This is a WRITE action that suppresses '
            . 'notifications — only use it when explicitly intended. Returns the created silenceID, '
            . 'which can later be removed with alertmanager_expire_silence.';
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
                'matchers' => [
                    'type' => 'array',
                    'description' => 'Label matchers that select which alerts to silence. At least one is required.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Label name, e.g. "alertname".'],
                            'value' => ['type' => 'string', 'description' => 'Label value or regex, e.g. "HighCpu".'],
                            'isRegex' => ['type' => 'boolean', 'description' => 'Treat value as a regex. Defaults to false.'],
                            'isEqual' => ['type' => 'boolean', 'description' => 'Match equality (true) or negation (false). Defaults to true.'],
                        ],
                        'required' => ['name', 'value'],
                    ],
                ],
                'comment' => [
                    'type' => 'string',
                    'description' => 'Reason for the silence (shown in the Alertmanager UI). Required.',
                ],
                'created_by' => [
                    'type' => 'string',
                    'description' => 'Author of the silence. Defaults to "prism".',
                ],
                'duration_minutes' => [
                    'type' => 'integer',
                    'description' => 'How long the silence lasts from now, in minutes. Defaults to 60. Ignored if both starts_at and ends_at are given.',
                ],
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'Optional explicit start time (RFC3339, e.g. "2026-06-30T12:00:00Z"). Defaults to now.',
                ],
                'ends_at' => [
                    'type' => 'string',
                    'description' => 'Optional explicit end time (RFC3339). Overrides duration_minutes when set.',
                ],
            ],
            'required' => ['matchers', 'comment'],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'alertmanager';
    }

    public function execute(array $arguments): array
    {
        $comment = trim((string) ($arguments['comment'] ?? ''));
        if ($comment === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "comment" argument is required (reason for the silence).']],
                'isError' => true,
            ];
        }

        $matchers = [];
        if (isset($arguments['matchers']) && is_array($arguments['matchers'])) {
            foreach ($arguments['matchers'] as $matcher) {
                if (!is_array($matcher)) {
                    continue;
                }
                $name = trim((string) ($matcher['name'] ?? ''));
                $value = (string) ($matcher['value'] ?? '');
                if ($name === '') {
                    continue;
                }
                $matchers[] = [
                    'name' => $name,
                    'value' => $value,
                    'isRegex' => (bool) ($matcher['isRegex'] ?? false),
                    'isEqual' => (bool) ($matcher['isEqual'] ?? true),
                ];
            }
        }

        if ($matchers === []) {
            return [
                'content' => [['type' => 'text', 'text' => 'At least one matcher with a non-empty "name" is required.']],
                'isError' => true,
            ];
        }

        $startsAt = trim((string) ($arguments['starts_at'] ?? ''));
        if ($startsAt === '') {
            $startsAt = gmdate('Y-m-d\TH:i:s\Z');
        }

        $endsAt = trim((string) ($arguments['ends_at'] ?? ''));
        if ($endsAt === '') {
            $duration = isset($arguments['duration_minutes']) ? max(1, (int) $arguments['duration_minutes']) : 60;
            $endsAt = gmdate('Y-m-d\TH:i:s\Z', time() + ($duration * 60));
        }

        try {
            $result = $this->alertmanagerService->createSilence(
                profileKey: $arguments['profile'] ?? null,
                matchers: $matchers,
                startsAt: $startsAt,
                endsAt: $endsAt,
                createdBy: trim((string) ($arguments['created_by'] ?? '')) ?: 'prism',
                comment: $comment,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'silenceID' => $result['silenceID'] ?? null,
                    'startsAt' => $startsAt,
                    'endsAt' => $endsAt,
                    'matchers' => $matchers,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error creating Alertmanager silence: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
