<?php

namespace App\Mcp\Tool;

use App\Matomo\MatomoService;

class MatomoGetVisitsSummaryTool implements ToolInterface
{
    public function __construct(
        private readonly MatomoService $matomoService,
    ) {
    }

    public function getName(): string
    {
        return 'matomo_get_visits_summary';
    }

    public function getDescription(): string
    {
        return 'Get a summary of visit metrics for a Matomo site over a period: number of visits, unique visitors, actions/pageviews, average visit duration, bounce count and bounce rate. Use matomo_list_sites first to find the idSite.';
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
                    'description' => 'Date or date range. Examples: "today", "yesterday", "2026-06-01", "last7", "last30", or "2026-05-01,2026-05-31" for a range. Defaults to today.',
                ],
                'segment' => [
                    'type' => 'string',
                    'description' => 'Optional Matomo segment definition to filter the data (e.g. "countryCode==nl").',
                ],
            ],
            'required' => [],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'matomo';
    }

    public function execute(array $arguments): array
    {
        try {
            $summary = $this->matomoService->getVisitsSummary(
                accountKey: $arguments['account'] ?? null,
                idSite: isset($arguments['idSite']) ? (int) $arguments['idSite'] : null,
                period: $arguments['period'] ?? 'day',
                date: $arguments['date'] ?? 'today',
                segment: $arguments['segment'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'period' => $arguments['period'] ?? 'day',
                    'date' => $arguments['date'] ?? 'today',
                    'summary' => $summary,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Matomo visits summary: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
