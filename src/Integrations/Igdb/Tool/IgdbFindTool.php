<?php

namespace App\Integrations\Igdb\Tool;

use App\Mcp\Tool\ToolInterface;

use App\Integrations\Igdb\IgdbService;

class IgdbFindTool implements ToolInterface
{
    public function __construct(
        private readonly IgdbService $igdbService,
    ) {
    }

    public function getName(): string
    {
        return 'igdb_find';
    }

    public function getDescription(): string
    {
        return 'Look up a game on IGDB by numeric id or slug (e.g. nier-automata). Returns a normalized record with title, synopsis, platforms, genres, external ids, and absolute coverUrl ready to download.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'string',
                    'description' => 'Numeric IGDB id or slug string (e.g. "119171" or "nier-automata")',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'IGDB account key (from igdb_list_accounts). Optional if only one account is configured.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'igdb';
    }

    public function execute(array $arguments): array
    {
        try {
            if (empty($arguments['id'])) {
                return [
                    'content' => [['type' => 'text', 'text' => 'Error: id is required']],
                    'isError' => true,
                ];
            }

            $record = $this->igdbService->find(
                idOrSlug: (string) $arguments['id'],
                accountKey: $arguments['account'] ?? null,
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $record,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error finding IGDB game: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
