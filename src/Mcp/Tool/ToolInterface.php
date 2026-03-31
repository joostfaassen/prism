<?php

namespace App\Mcp\Tool;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema for the tool's input parameters.
     *
     * @return array{type: string, properties: array<string, mixed>, required?: list<string>}
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @return array{content: list<array{type: string, text: string}>, isError?: bool}
     */
    public function execute(array $arguments): array;
}
