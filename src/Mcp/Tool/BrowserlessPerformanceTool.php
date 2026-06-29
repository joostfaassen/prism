<?php

namespace App\Mcp\Tool;

use App\Browserless\BrowserlessService;

class BrowserlessPerformanceTool implements ToolInterface
{
    public function __construct(
        private readonly BrowserlessService $browserlessService,
    ) {
    }

    public function getName(): string
    {
        return 'browserless_performance';
    }

    public function getDescription(): string
    {
        return 'Run a Lighthouse performance audit against a web page using a real headless browser. '
            . 'By default returns a compact summary: category scores (performance, accessibility, '
            . 'best-practices, seo, pwa) on a 0-100 scale plus core web vitals (FCP, LCP, TBT, CLS, Speed Index). '
            . 'Limit the audit with categories, or set full=true for the complete Lighthouse JSON report.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Browserless account key. Optional if only one is configured.'],
                'url' => ['type' => 'string', 'description' => 'The fully-qualified URL to audit (http/https).'],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['performance', 'accessibility', 'best-practices', 'seo', 'pwa']],
                    'description' => 'Restrict the audit to these Lighthouse categories. Omit to run all of them.',
                ],
                'full' => ['type' => 'boolean', 'description' => 'Return the complete Lighthouse report instead of the compact summary. Default false (can be very large).'],
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

        $config = [];
        $categories = $arguments['categories'] ?? null;
        if (is_array($categories) && $categories !== []) {
            $config = [
                'extends' => 'lighthouse:default',
                'settings' => [
                    'onlyCategories' => array_values(array_map('strval', $categories)),
                ],
            ];
        }

        try {
            $result = $this->browserlessService->performance(
                $arguments['account'] ?? null,
                $url,
                $config,
                (bool) ($arguments['full'] ?? false),
            );

            return [
                'content' => [['type' => 'text', 'text' => json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error running performance audit: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
