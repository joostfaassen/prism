<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Repository\DocumentRepository;

class DocumentGetTool implements ToolInterface
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'document_get';
    }

    public function getDescription(): string
    {
        return 'Get detailed information about a document by xuid or name. Returns type, name, summary, parsed config data, and a list of note summaries (use document_note_get to fetch full note content).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'xuid' => [
                    'type' => 'string',
                    'description' => 'The document XUID (22-character identifier)',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => "The document's name (case-sensitive exact match)",
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
            $document = null;

            if (isset($arguments['xuid'])) {
                $document = $this->documentRepository->findOneByServerAndXuid($serverName, $arguments['xuid']);
            } elseif (isset($arguments['name'])) {
                $document = $this->documentRepository->findOneByServerAndName($serverName, $arguments['name']);
            } else {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: provide either "xuid" or "name" to look up a document.']],
                    'isError' => true,
                ];
            }

            if ($document === null) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Document not found.']],
                    'isError' => true,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode($document->toArray(includeNotes: true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting document: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
