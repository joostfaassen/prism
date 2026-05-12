<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Repository\NodeRepository;

class NodeGetTool implements ToolInterface
{
    public function __construct(
        private readonly NodeRepository $nodeRepository,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'node_get';
    }

    public function getDescription(): string
    {
        return 'Get detailed information about a node by xuid or name. Returns type, name, summary, parsed config data, and a list of note summaries (use node_note_get to fetch full note content).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'xuid' => [
                    'type' => 'string',
                    'description' => 'The node XUID (22-character identifier)',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => "The node's name (case-sensitive exact match)",
                ],
            ],
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
            $node = null;

            if (isset($arguments['xuid'])) {
                $node = $this->nodeRepository->findOneByServerAndXuid($serverName, $arguments['xuid']);
            } elseif (isset($arguments['name'])) {
                $node = $this->nodeRepository->findOneByServerAndName($serverName, $arguments['name']);
            } else {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: provide either "xuid" or "name" to look up a node.']],
                    'isError' => true,
                ];
            }

            if ($node === null) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Node not found.']],
                    'isError' => true,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode($node->toArray(includeNotes: true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting node: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
