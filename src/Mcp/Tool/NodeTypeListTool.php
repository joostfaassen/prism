<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Repository\NodeTypeRepository;

class NodeTypeListTool implements ToolInterface
{
    public function __construct(
        private readonly NodeTypeRepository $nodeTypeRepository,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'node_type_list';
    }

    public function getDescription(): string
    {
        return 'List all node types on this server (e.g. Contact, Location, Project). Each type defines a category of nodes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function getAccountType(): ?string
    {
        return null;
    }

    public function execute(array $arguments): array
    {
        try {
            $serverName = $this->serverContext->getServerName();
            $types = $this->nodeTypeRepository->findByServer($serverName);

            $result = array_map(fn($t) => $t->toArray(), $types);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing node types: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
