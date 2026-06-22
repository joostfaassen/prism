<?php

namespace App\Mcp\Tool;

use App\SendGrid\SendGridService;

class SendGridGetTool implements ToolInterface
{
    public function __construct(
        private readonly SendGridService $sendGridService,
    ) {
    }

    public function getName(): string
    {
        return 'sendgrid_get';
    }

    public function getDescription(): string
    {
        return 'Run an arbitrary read-only GET request against any SendGrid v3 API endpoint and return the raw JSON. '
            . 'Use this for flexible ad-hoc stats queries not covered by the dedicated SendGrid tools, e.g. '
            . '"/v3/devices/stats", "/v3/geo/stats", "/v3/clients/stats", "/v3/mailbox_providers/stats", '
            . '"/v3/browsers/stats" or "/v3/subusers/stats". '
            . 'Provide the path and optional query parameters. Only GET requests are allowed; the path must start with "/v3/".';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'SendGrid account key. Optional if only one account is configured.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'SendGrid v3 API path, e.g. "/v3/geo/stats". Must start with "/v3/".',
                ],
                'query' => [
                    'type' => 'object',
                    'description' => 'Optional query parameters as key/value pairs (e.g. {"start_date": "2026-01-01", "aggregated_by": "day"}). Values may be strings, numbers, booleans, or arrays of those for repeated parameters.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'sendgrid';
    }

    public function execute(array $arguments): array
    {
        $path = trim((string) ($arguments['path'] ?? ''));

        if ($path === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "path" argument is required, e.g. "/v3/geo/stats".']],
                'isError' => true,
            ];
        }

        if (!str_starts_with($path, '/v3/')) {
            return [
                'content' => [['type' => 'text', 'text' => sprintf(
                    'Unsupported path "%s". Only read-only paths starting with "/v3/" are allowed.',
                    $path,
                )]],
                'isError' => true,
            ];
        }

        $query = [];
        if (isset($arguments['query']) && is_array($arguments['query'])) {
            foreach ($arguments['query'] as $name => $value) {
                if (is_array($value)) {
                    $items = [];
                    foreach ($value as $item) {
                        if (is_scalar($item)) {
                            $items[] = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                        }
                    }
                    $query[(string) $name] = $items;

                    continue;
                }

                if (is_scalar($value)) {
                    $query[(string) $name] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                }
            }
        }

        try {
            $result = $this->sendGridService->get(
                accountKey: $arguments['account'] ?? null,
                path: $path,
                query: $query,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'path' => $path,
                    'result' => $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running SendGrid request: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
