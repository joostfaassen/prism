<?php

namespace App\Mcp\Tool;

use App\Browserless\BrowserlessService;

class BrowserlessPdfTool implements ToolInterface
{
    public function __construct(
        private readonly BrowserlessService $browserlessService,
    ) {
    }

    public function getName(): string
    {
        return 'browserless_pdf';
    }

    public function getDescription(): string
    {
        return 'Render a web page to PDF in a real headless browser and return it as base64. '
            . 'Preserves layout, fonts and print styles. Control paper size with format (A4, Letter, ...), '
            . 'orientation with landscape, and whether to print background graphics.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Browserless account key. Optional if only one is configured.'],
                'url' => ['type' => 'string', 'description' => 'The fully-qualified URL to render (http/https).'],
                'format' => ['type' => 'string', 'description' => 'Paper format, e.g. A4, A3, Letter, Legal, Tabloid. Default A4.'],
                'landscape' => ['type' => 'boolean', 'description' => 'Use landscape orientation. Default false.'],
                'print_background' => ['type' => 'boolean', 'description' => 'Print background graphics/colors. Default false.'],
                'scale' => ['type' => 'number', 'description' => 'Rendering scale, 0.1 - 2.0. Default 1.0.'],
                'options' => [
                    'type' => 'object',
                    'description' => 'Advanced Puppeteer PDF options merged into the request (e.g. margin, displayHeaderFooter, headerTemplate). Overrides the convenience fields above.',
                ],
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

        $options = [];
        if (!empty($arguments['format'])) {
            $options['format'] = (string) $arguments['format'];
        }
        if (array_key_exists('landscape', $arguments)) {
            $options['landscape'] = (bool) $arguments['landscape'];
        }
        if (array_key_exists('print_background', $arguments)) {
            $options['printBackground'] = (bool) $arguments['print_background'];
        }
        if (isset($arguments['scale']) && $arguments['scale'] !== '') {
            $options['scale'] = (float) $arguments['scale'];
        }
        if (isset($arguments['options']) && is_array($arguments['options'])) {
            $options = array_merge($options, $arguments['options']);
        }

        try {
            $result = $this->browserlessService->pdf($arguments['account'] ?? null, $url, $options);

            return [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'url' => $url,
                    'mime_type' => $result['mime_type'],
                    'bytes' => $result['bytes'],
                    'encoding' => 'base64',
                    'base64' => $result['base64'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error rendering PDF: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
