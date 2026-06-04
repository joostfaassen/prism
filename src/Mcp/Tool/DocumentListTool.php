<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Repository\DocumentRepository;

class DocumentListTool implements ToolInterface
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'document_list';
    }

    public function getDescription(): string
    {
        return 'List all documents on this server. Optionally filter by type name (e.g. "Contact", "Location"). Returns xuid, type, name, summary, and parsed config data.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'description' => 'Filter by document type name (e.g. "Contact", "Location"). Omit to list all documents.',
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
            $typeName = isset($arguments['type']) && trim($arguments['type']) !== '' ? $arguments['type'] : null;
            $documents = $this->documentRepository->findByServer($serverName, $typeName);

            $result = array_map(fn($n) => $n->toArray(), $documents);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error listing documents: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
