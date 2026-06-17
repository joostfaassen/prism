<?php

namespace App\Mcp\Tool;

use App\Matomo\MatomoService;

class MatomoGetTopPagesTool implements ToolInterface
{
    public function __construct(
        private readonly MatomoService $matomoService,
    ) {
    }

    public function getName(): string
    {
        return 'matomo_get_top_pages';
    }

    public function getDescription(): string
    {
        return 'Get the most visited page URLs for a Matomo site over a period, including visits, hits, time spent and bounce/exit rates. Useful for "what are the top pages" questions. Use matomo_list_sites first to find the idSite.';
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
                    'description' => 'Date or date range. Examples: "today", "yesterday", "2026-06-01", "last7", "2026-05-01,2026-05-31". Defaults to today.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of pages to return. Defaults to 25.',
                ],
                'segment' => [
                    'type' => 'string',
                    'description' => 'Optional Matomo segment definition to filter the data.',
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
            $pages = $this->matomoService->getTopPageUrls(
                accountKey: $arguments['account'] ?? null,
                idSite: isset($arguments['idSite']) ? (int) $arguments['idSite'] : null,
                period: $arguments['period'] ?? 'day',
                date: $arguments['date'] ?? 'today',
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 25,
                segment: $arguments['segment'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'period' => $arguments['period'] ?? 'day',
                    'date' => $arguments['date'] ?? 'today',
                    'count' => count($pages),
                    'pages' => $pages,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching Matomo top pages: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
