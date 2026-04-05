<?php

namespace App\Mcp;

use App\Config\ServerContext;
use App\Mcp\Tool\ToolInterface;

class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_VERSION = '1.0.0';

    /** @var array<string, ToolInterface> */
    private array $toolMap = [];

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        iterable $tools,
        private readonly ServerContext $serverContext,
    ) {
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

    public function getTool(string $name): ?ToolInterface
    {
        return $this->toolMap[$name] ?? null;
    }

    /**
     * @return list<ToolInterface>
     */
    public function getToolsForServer(): array
    {
        if (!$this->serverContext->hasServer()) {
            return $this->getTools();
        }

        $server = $this->serverContext->getServer();

        return array_values(array_filter(
            $this->toolMap,
            fn(ToolInterface $tool) => $tool->getAccountType() === null
                || $server->hasAccountType($tool->getAccountType()),
        ));
    }

    private function handleInitialize(): array
    {
        $serverName = 'prism';
        if ($this->serverContext->hasServer()) {
            $serverName .= '/' . $this->serverContext->getServerName();
        }

        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => $serverName,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->getToolsForServer() as $tool) {
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

        if ($this->serverContext->hasServer() && $tool->getAccountType() !== null) {
            if (!$this->serverContext->getServer()->hasAccountType($tool->getAccountType())) {
                return [
                    'content' => [['type' => 'text', 'text' => "Tool \"{$name}\" is not available on this server"]],
                    'isError' => true,
                ];
            }
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
