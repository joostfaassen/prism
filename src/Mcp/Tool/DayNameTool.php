<?php

namespace App\Mcp\Tool;

class DayNameTool implements ToolInterface
{
    public function getName(): string
    {
        return 'day_name';
    }

    public function getDescription(): string
    {
        return 'Get the day of the week name for a given date in YYYY-MM-DD format (e.g. 2026-01-03 returns Saturday)';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Date in YYYY-MM-DD format (e.g. 2026-01-03)',
                    'pattern' => '^\d{4}-\d{2}-\d{2}$',
                ],
            ],
            'required' => ['date'],
        ];
    }

    public function getAccountType(): ?string
    {
        return null;
    }

    public function execute(array $arguments): array
    {
        $dateString = $arguments['date'] ?? '';

        if ($dateString === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "date" is required']],
                'isError' => true,
            ];
        }

        try {
            $date = new \DateTimeImmutable($dateString);
        } catch (\Exception) {
            return [
                'content' => [['type' => 'text', 'text' => sprintf('Invalid date format: "%s". Expected YYYY-MM-DD.', $dateString)]],
                'isError' => true,
            ];
        }

        $dayName = $date->format('l');

        return [
            'content' => [['type' => 'text', 'text' => json_encode([
                'date' => $date->format('Y-m-d'),
                'day_name' => $dayName,
                'day_of_week' => (int) $date->format('N'),
            ])]],
        ];
    }
}
