<?php

namespace App\Mcp\Tool;

use App\Config\ServerContext;
use App\Repository\NodeNoteRepository;

class NodeNoteGetTool implements ToolInterface
{
    public function __construct(
        private readonly NodeNoteRepository $noteRepository,
        private readonly ServerContext $serverContext,
    ) {
    }

    public function getName(): string
    {
        return 'node_note_get';
    }

    public function getDescription(): string
    {
        return 'Get the full content of a node note by its XUID. Returns summary and detailed content.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'xuid' => [
                    'type' => 'string',
                    'description' => 'The note XUID (22-character identifier)',
                ],
            ],
            'required' => ['xuid'],
        ];
    }

    public function getAccountType(): ?string
    {
        return null;
    }

    public function execute(array $arguments): array
    {
        try {
            if (!isset($arguments['xuid']) || trim($arguments['xuid']) === '') {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: "xuid" is required.']],
                    'isError' => true,
                ];
            }

            $note = $this->noteRepository->findOneByXuid($arguments['xuid']);

            if ($note === null) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Note not found.']],
                    'isError' => true,
                ];
            }

            $serverName = $this->serverContext->getServerName();
            if ($note->getNode()->getServerName() !== $serverName) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Note not found.']],
                    'isError' => true,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode($note->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error getting note: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
