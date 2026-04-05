<?php

namespace App\Mcp\Tool;

class SumTool implements ToolInterface
{
    public function getName(): string
    {
        return 'sum';
    }

    public function getDescription(): string
    {
        return 'Calculate the sum of an array of numeric values';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'values' => [
                    'type' => 'array',
                    'items' => ['type' => 'number'],
                    'description' => 'Array of numeric values to sum',
                ],
            ],
            'required' => ['values'],
        ];
    }

    public function getAccountType(): ?string
    {
        return null;
    }

    public function execute(array $arguments): array
    {
        $values = $arguments['values'] ?? [];

        if (!is_array($values)) {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "values" must be an array']],
                'isError' => true,
            ];
        }

        foreach ($values as $i => $v) {
            if (!is_numeric($v)) {
                return [
                    'content' => [['type' => 'text', 'text' => sprintf('Value at index %d is not numeric: %s', $i, json_encode($v))]],
                    'isError' => true,
                ];
            }
        }

        $sum = array_sum($values);

        return [
            'content' => [['type' => 'text', 'text' => json_encode(['sum' => $sum, 'count' => count($values)])]],
        ];
    }
}
