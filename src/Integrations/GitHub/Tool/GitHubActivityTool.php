<?php

namespace App\Integrations\GitHub\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\GitHub\GitHubService;

class GitHubActivityTool implements ToolInterface
{
    private const TIMEZONE = 'Europe/Amsterdam';

    public function __construct(
        private readonly GitHubService $gitHubService,
    ) {
    }

    public function getName(): string
    {
        return 'github_activity';
    }

    public function getDescription(): string
    {
        return 'Summarize a GitHub user\'s activity in a timespan — built for day-by-day activity/pulse timelines. '
            . 'Give a single day via "date" (YYYY-MM-DD, or "today"/"yesterday"), or a range via "from"/"to". '
            . 'Returns buckets: commits authored (with author-date), pull requests created, pull requests merged, '
            . 'pull requests reviewed, and issues created — each with repo, title/message, timestamp and URL. '
            . 'Defaults to the token owner if no "login" is given. Uses the GitHub Search API.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profile' => [
                    'type' => 'string',
                    'description' => 'GitHub profile key. Optional if only one profile is configured.',
                ],
                'login' => [
                    'type' => 'string',
                    'description' => 'GitHub username to report on. Optional; defaults to the profile default_login or the token owner.',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Single day to report on: YYYY-MM-DD, or "today"/"yesterday" (Europe/Amsterdam). Ignored if "from"/"to" are given.',
                ],
                'from' => [
                    'type' => 'string',
                    'description' => 'Start date (YYYY-MM-DD) of the range, inclusive. Use with "to".',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'End date (YYYY-MM-DD) of the range, inclusive. Use with "from".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max items per bucket (1-100). Defaults to 100.',
                ],
            ],
            'required' => [],
        ];
    }

    public function getProfileType(): ?string
    {
        return 'github';
    }

    public function execute(array $arguments): array
    {
        try {
            [$from, $to] = $this->resolveRange($arguments);
        } catch (\InvalidArgumentException $e) {
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }

        try {
            $result = $this->gitHubService->getActivity(
                profileKey: $arguments['profile'] ?? null,
                login: $arguments['login'] ?? null,
                from: $from,
                to: $to,
                limit: isset($arguments['limit']) ? (int) $arguments['limit'] : 100,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching GitHub activity: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{0: string, 1: string} [from, to] as YYYY-MM-DD
     */
    private function resolveRange(array $arguments): array
    {
        $from = isset($arguments['from']) ? trim((string) $arguments['from']) : '';
        $to = isset($arguments['to']) ? trim((string) $arguments['to']) : '';

        if ($from !== '' || $to !== '') {
            if ($from === '' || $to === '') {
                throw new \InvalidArgumentException('Provide both "from" and "to" for a range, or use "date" for a single day.');
            }

            return [$this->normalizeDate($from), $this->normalizeDate($to)];
        }

        $date = isset($arguments['date']) ? trim((string) $arguments['date']) : '';
        if ($date === '') {
            $date = 'today';
        }

        $day = $this->normalizeDate($date);

        return [$day, $day];
    }

    private function normalizeDate(string $value): string
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $lower = strtolower($value);

        if ($lower === 'today') {
            return (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        }
        if ($lower === 'yesterday') {
            return (new \DateTimeImmutable('yesterday', $tz))->format('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid date "%s". Use YYYY-MM-DD, "today", or "yesterday".',
                $value,
            ));
        }

        return $value;
    }
}
