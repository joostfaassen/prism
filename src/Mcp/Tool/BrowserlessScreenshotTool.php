<?php

namespace App\Mcp\Tool;

use App\Browserless\BrowserlessService;

class BrowserlessScreenshotTool implements ToolInterface
{
    public function __construct(
        private readonly BrowserlessService $browserlessService,
    ) {
    }

    public function getName(): string
    {
        return 'browserless_screenshot';
    }

    public function getDescription(): string
    {
        return 'Render a web page in a real headless browser and return a screenshot as an image. '
            . 'Use full_page to capture the entire scrollable page, or width/height to set the viewport. '
            . 'Defaults to a PNG; set type=jpeg (with optional quality) for smaller files.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account' => ['type' => 'string', 'description' => 'Browserless account key. Optional if only one is configured.'],
                'url' => ['type' => 'string', 'description' => 'The fully-qualified URL to screenshot (http/https).'],
                'full_page' => ['type' => 'boolean', 'description' => 'Capture the full scrollable page instead of just the viewport. Default false.'],
                'type' => ['type' => 'string', 'enum' => ['png', 'jpeg'], 'description' => 'Image format. Default png.'],
                'quality' => ['type' => 'integer', 'description' => 'JPEG quality 0-100 (only used when type=jpeg).'],
                'width' => ['type' => 'integer', 'description' => 'Viewport width in pixels.'],
                'height' => ['type' => 'integer', 'description' => 'Viewport height in pixels.'],
                'options' => [
                    'type' => 'object',
                    'description' => 'Advanced Puppeteer screenshot options merged into the request (e.g. clip, omitBackground). Overrides the convenience fields above.',
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
        if (array_key_exists('full_page', $arguments)) {
            $options['fullPage'] = (bool) $arguments['full_page'];
        }
        if (!empty($arguments['type'])) {
            $options['type'] = (string) $arguments['type'];
        }
        if (isset($arguments['quality']) && $arguments['quality'] !== '') {
            $options['quality'] = (int) $arguments['quality'];
        }

        $width = isset($arguments['width']) ? (int) $arguments['width'] : 0;
        $height = isset($arguments['height']) ? (int) $arguments['height'] : 0;
        if ($width > 0 || $height > 0) {
            $options['viewport'] = array_filter([
                'width' => $width > 0 ? $width : null,
                'height' => $height > 0 ? $height : null,
            ], static fn ($v) => $v !== null);
        }

        if (isset($arguments['options']) && is_array($arguments['options'])) {
            $options = array_merge($options, $arguments['options']);
        }

        try {
            $result = $this->browserlessService->screenshot($arguments['account'] ?? null, $url, $options);

            return [
                'content' => [
                    [
                        'type' => 'image',
                        'data' => $result['base64'],
                        'mimeType' => $result['mime_type'],
                    ],
                    [
                        'type' => 'text',
                        'text' => sprintf('Screenshot of %s (%s, %d bytes).', $url, $result['mime_type'], $result['bytes']),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [['type' => 'text', 'text' => 'Error capturing screenshot: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }
}
