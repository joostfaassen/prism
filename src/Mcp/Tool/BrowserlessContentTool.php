<?php

namespace App\Mcp\Tool;

use App\Browserless\BrowserlessService;

class BrowserlessContentTool implements ToolInterface
{
    public function __construct(
        private readonly BrowserlessService $browserlessService,
    ) {
    }

    public function getName(): string
    {
        return 'browserless_content';
    }

    public function getDescription(): string
    {
        return 'Load a web page in a real headless browser (executing JavaScript) and return its fully '
            . 'rendered HTML. Useful for scraping pages that build their content client-side, where a plain '
            . 'HTTP fetch would return an empty shell.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Browserless account key. Optional if only one is configured.'],
                'url' => ['type' => 'string', 'description' => 'The fully-qualified URL to load (http/https).'],
                'max_bytes' => ['type' => 'integer', 'description' => 'Truncate the returned HTML to this many bytes. Default 0 (no limit).'],
            ],
            'required' => ['url'],
        ];
    }

    public function getAccountType(): ?string
    {
        return 'browserless';
    }

    public function execute(array $arguments): array
    {
        $url = trim((string) ($arguments['url'] ?? ''));
        if ($url === '') {
            return [
                'content' => [['type' => 'text', 'text' => 'Parameter "url" is required']],
                'isError' => true,
            ];
        }

        try {
            $result = $this->browserlessService->content($arguments['account'] ?? null, $url);

            $html = $result['html'];
            $truncated = false;
            $maxBytes = (int) ($arguments['max_bytes'] ?? 0);
            if ($maxBytes > 0 && strlen($html) > $maxBytes) {
                $html = substr($html, 0, $maxBytes);
                $truncated = true;
            }

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'url' => $result['url'],
                    'bytes' => $result['bytes'],
                    'truncated' => $truncated,
                    'html' => $html,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error fetching content: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
