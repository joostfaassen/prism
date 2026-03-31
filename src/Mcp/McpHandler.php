<?php

namespace App\Mcp;

use App\Mcp\Tool\ToolInterface;

class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'joost-bridge';
    private const SERVER_VERSION = '1.0.0';

    /** @var array<string, ToolInterface> */
    private array $toolMap = [];

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->toolMap[$tool->getName()] = $tool;
        }
    }

    public function handleRequest(array $request): array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        $result = match ($method) {
            'initialize' => $this->handleInitialize(),
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params),
            'notifications/initialized' => null,
            'ping' => [],
            default => $this->errorResponse($id, -32601, "Method not found: {$method}"),
        };

        if ($result === null) {
            return [];
        }

        if (isset($result['error'])) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => $result['error'],
            ];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return list<ToolInterface>
     */
    public function getTools(): array
    {
        return array_values($this->toolMap);
    }

    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->toolMap as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return ['tools' => $tools];
    }

    private function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $tool = $this->toolMap[$name] ?? null;
        if ($tool === null) {
            return [
                'content' => [['type' => 'text', 'text' => "Unknown tool: {$name}"]],
                'isError' => true,
            ];
        }

        try {
            return $tool->execute($arguments);
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Tool error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    private function errorResponse(?int $id, int $code, string $message): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
