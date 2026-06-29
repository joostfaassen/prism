<?php

namespace App\Mcp\Tool\Apify;

/**
 * Example tool wrapping the "apify/rag-web-browser" actor.
 *
 * It performs a web search (or fetches a single URL) and returns the page
 * content as Markdown — handy as a lightweight web-research tool for AI
 * clients. Use this class as a template for wrapping other actors.
 *
 * @see https://apify.com/apify/rag-web-browser
 */
class ApifyWebSearchTool extends AbstractApifyActorTool
{
    private const MAX_MARKDOWN_CHARS = 8000;

    protected function getActorId(): string
    {
        return 'apify/rag-web-browser';
    }

    public function getName(): string
    {
        return 'apify_web_search';
    }

    public function getDescription(): string
    {
        return 'Search the web (or fetch a single URL) and return the page content as Markdown, via the Apify rag-web-browser actor. Good for up-to-date web research: pass a search query or a URL.';
    }

    protected function getProperties(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'A Google search query (e.g. "best PHP frameworks 2026") or a single URL to fetch and convert to Markdown.',
            ],
        ];
    }

    protected function getRequired(): array
    {
        return ['query'];
    }

    protected function buildActorInput(array $arguments): array
    {
        $input = [
            'query' => (string) ($arguments['query'] ?? ''),
            'outputFormats' => ['markdown'],
        ];

        if (isset($arguments['max_items']) && $arguments['max_items'] !== '') {
            $input['maxResults'] = (int) $arguments['max_items'];
        }

        return $input;
    }

    protected function transformOutput(array $items, array $arguments): mixed
    {
        $results = [];
        foreach ($items as $item) {
            $search = is_array($item['searchResult'] ?? null) ? $item['searchResult'] : [];
            $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
            $markdown = is_string($item['markdown'] ?? null) ? $item['markdown'] : null;

            if ($markdown !== null && mb_strlen($markdown) > self::MAX_MARKDOWN_CHARS) {
                $markdown = mb_substr($markdown, 0, self::MAX_MARKDOWN_CHARS) . "\n\n…[truncated]";
            }

            $results[] = [
                'title' => $search['title'] ?? $metadata['title'] ?? null,
                'url' => $search['url'] ?? $metadata['url'] ?? null,
                'description' => $search['description'] ?? $metadata['description'] ?? null,
                'markdown' => $markdown,
            ];
        }

        return [
            'count' => count($results),
            'results' => $results,
        ];
    }
}
