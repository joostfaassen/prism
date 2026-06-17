<?php

namespace App\Mcp\Tool;

use App\Matomo\MatomoService;

class MatomoGetReportTool implements ToolInterface
{
    public function __construct(
        private readonly MatomoService $matomoService,
    ) {
    }

    public function getName(): string
    {
        return 'matomo_get_report';
    }

    public function getDescription(): string
    {
        return 'Run an arbitrary read-only Matomo Reporting API report and return the raw result. '
            . 'Supply the API method (e.g. "Referrers.getReferrerType", "Referrers.getAll", '
            . '"VisitTime.getVisitInformationPerServerTime", "DevicesDetection.getType", '
            . '"UserCountry.getCountry", "Actions.getPageTitles") plus idSite, period and date. '
            . 'Use this for simple ad-hoc queries not covered by the dedicated Matomo tools. '
            . 'Only "get*" reporting methods are allowed.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => [
                    'type' => 'string',
                    'description' => 'Matomo account key. Optional if only one account is configured.',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'Matomo Reporting API method, formatted as "Module.method", e.g. "Referrers.getReferrerType".',
                ],
                'idSite' => [
                    'type' => 'integer',
                    'description' => 'Site id to query (from matomo_list_sites). Optional if a default_id_site is configured for the account.',
                ],
                'period' => [
                    'type' => 'string',
                    'description' => 'Reporting period: day, week, month, year, or range. Defaults to day.',
                    'enum' => ['day', 'week', 'month', 'year', 'range'],
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Date or date range. Examples: "today", "yesterday", "2026-06-01", "last7", "2026-05-01,2026-05-31". Defaults to today.',
                ],
                'segment' => [
                    'type' => 'string',
                    'description' => 'Optional Matomo segment definition to filter the data.',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Optional extra Matomo parameters as key/value pairs (e.g. {"filter_limit": 10, "flat": 1}). Values must be strings, numbers or booleans.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['method'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'matomo';
    }

    public function execute(array $arguments): array
    {
        $method = trim((string) ($arguments['method'] ?? ''));

        if ($method === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'The "method" argument is required, e.g. "Referrers.getReferrerType".']],
                'isError' => true,
            ];
        }

        if (!preg_match('/^[A-Za-z0-9]+\.get[A-Za-z0-9]*$/', $method)) {
            return [
                'content' => [['type' => 'text', 'text' => sprintf(
                    'Unsupported method "%s". Only read-only "Module.get..." reporting methods are allowed.',
                    $method,
                )]],
                'isError' => true,
            ];
        }

        $params = [];
        if (isset($arguments['params']) && is_array($arguments['params'])) {
            foreach ($arguments['params'] as $name => $value) {
                if (is_scalar($value)) {
                    $params[(string) $name] = is_bool($value) ? ($value ? '1' : '0') : $value;
                }
            }
        }

        try {
            $result = $this->matomoService->getReport(
                accountKey: $arguments['account'] ?? null,
                method: $method,
                idSite: isset($arguments['idSite']) ? (int) $arguments['idSite'] : null,
                period: $arguments['period'] ?? 'day',
                date: $arguments['date'] ?? 'today',
                segment: $arguments['segment'] ?? null,
                params: $params,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'method' => $method,
                    'period' => $arguments['period'] ?? 'day',
                    'date' => $arguments['date'] ?? 'today',
                    'result' => $result,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running Matomo report: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
