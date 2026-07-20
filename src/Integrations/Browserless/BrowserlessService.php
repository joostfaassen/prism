<?php

namespace App\Integrations\Browserless;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin client around a (self-hosted) Browserless v2 instance.
 *
 * Browserless exposes simple REST endpoints that drive a real headless
 * browser: /screenshot, /content, /pdf and /performance (Lighthouse). Each is
 * a POST with a JSON body describing the target URL and options. The API token
 * is passed as a `?token=` query parameter, which every Browserless v2
 * deployment (cloud and Docker) accepts.
 *
 * @see https://docs.browserless.io/
 */
class BrowserlessService
{
    public function __construct(
        private readonly BrowserlessConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, base_url: string}>
     */
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'base_url' => $profile->baseUrl,
            ];
        }

        return $profiles;
    }

    /**
     * Capture a screenshot of a page and return it base64-encoded.
     *
     * @param array<string, mixed> $options Puppeteer screenshot options (fullPage, type, quality, clip, ...)
     *
     * @return array{mime_type: string, bytes: int, base64: string}
     */
    public function screenshot(?string $profileKey, string $url, array $options = []): array
    {
        $profile = $this->resolveProfile($profileKey);
        $this->assertUrl($url);

        $type = isset($options['type']) ? strtolower((string) $options['type']) : 'png';
        $mimeType = $type === 'jpeg' || $type === 'jpg' ? 'image/jpeg' : 'image/png';

        $body = ['url' => $url];
        if ($options !== []) {
            $body['options'] = $options;
        }

        $response = $this->post($profile, '/screenshot', $body);
        $this->assertOk($response, 'capture screenshot');

        $bytes = $response->getContent();

        return [
            'mime_type' => $mimeType,
            'bytes' => strlen($bytes),
            'base64' => base64_encode($bytes),
        ];
    }

    /**
     * Fetch the fully rendered HTML content of a page.
     *
     * @param array<string, mixed> $extra Additional body parameters (e.g. gotoOptions, rejectResourceTypes)
     *
     * @return array{url: string, bytes: int, html: string}
     */
    public function content(?string $profileKey, string $url, array $extra = []): array
    {
        $profile = $this->resolveProfile($profileKey);
        $this->assertUrl($url);

        $response = $this->post($profile, '/content', array_merge($extra, ['url' => $url]));
        $this->assertOk($response, 'fetch content');

        $html = $response->getContent();

        return [
            'url' => $url,
            'bytes' => strlen($html),
            'html' => $html,
        ];
    }

    /**
     * Render a page to PDF and return it base64-encoded.
     *
     * @param array<string, mixed> $options Puppeteer PDF options (format, landscape, printBackground, scale, ...)
     *
     * @return array{mime_type: string, bytes: int, base64: string}
     */
    public function pdf(?string $profileKey, string $url, array $options = []): array
    {
        $profile = $this->resolveProfile($profileKey);
        $this->assertUrl($url);

        $body = ['url' => $url];
        if ($options !== []) {
            $body['options'] = $options;
        }

        $response = $this->post($profile, '/pdf', $body);
        $this->assertOk($response, 'render PDF');

        $bytes = $response->getContent();

        return [
            'mime_type' => 'application/pdf',
            'bytes' => strlen($bytes),
            'base64' => base64_encode($bytes),
        ];
    }

    /**
     * Run a Lighthouse performance audit against a page.
     *
     * @param array<string, mixed> $config Lighthouse config (e.g. {"extends":"lighthouse:default","settings":{"onlyCategories":["performance"]}})
     *
     * @return array<string, mixed> Trimmed summary, or the full Lighthouse report when $full is true.
     */
    public function performance(?string $profileKey, string $url, array $config = [], bool $full = false): array
    {
        $profile = $this->resolveProfile($profileKey);
        $this->assertUrl($url);

        $body = ['url' => $url];
        if ($config !== []) {
            $body['config'] = $config;
        }

        $response = $this->post($profile, '/performance', $body);
        $this->assertOk($response, 'run performance audit');

        $raw = $response->getContent();
        $decoded = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        // Browserless wraps the Lighthouse result under "data".
        $report = $decoded['data'] ?? $decoded;
        if (!is_array($report)) {
            $report = [];
        }

        if ($full) {
            return $report;
        }

        return $this->summarizeLighthouse($report, $url);
    }

    /**
     * Reduce a (very large) Lighthouse report to the scores and core web
     * vitals most callers care about.
     *
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function summarizeLighthouse(array $report, string $url): array
    {
        $categories = [];
        foreach ((array) ($report['categories'] ?? []) as $id => $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $score = $cat['score'] ?? null;
            $categories[(string) $id] = [
                'title' => $cat['title'] ?? $id,
                'score' => is_numeric($score) ? (int) round(((float) $score) * 100) : null,
            ];
        }

        $metricIds = [
            'first-contentful-paint',
            'largest-contentful-paint',
            'speed-index',
            'total-blocking-time',
            'cumulative-layout-shift',
            'interactive',
        ];
        $metrics = [];
        $audits = (array) ($report['audits'] ?? []);
        foreach ($metricIds as $id) {
            $audit = $audits[$id] ?? null;
            if (is_array($audit)) {
                $metrics[$id] = $audit['displayValue'] ?? $audit['numericValue'] ?? null;
            }
        }

        return [
            'requested_url' => $report['requestedUrl'] ?? $url,
            'final_url' => $report['finalUrl'] ?? $report['mainDocumentUrl'] ?? null,
            'fetch_time' => $report['fetchTime'] ?? null,
            'lighthouse_version' => $report['lighthouseVersion'] ?? null,
            'scores' => $categories,
            'metrics' => $metrics,
            'note' => 'Scores are 0-100. Call again with full=true for the complete Lighthouse report.',
        ];
    }

    private function resolveProfile(?string $profileKey): BrowserlessProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            $profile = $this->configLoader->getProfile($profileKey);
        } else {
            $profiles = $this->configLoader->getProfiles();
            if ($profiles === []) {
                throw new \RuntimeException('No Browserless profiles configured for this server');
            }
            $profile = reset($profiles);
        }

        if (!$profile->hasCredentials()) {
            throw new \RuntimeException(sprintf(
                'Browserless profile "%s" is missing base_url or token',
                $profile->key,
            ));
        }

        return $profile;
    }

    private function assertUrl(string $url): void
    {
        if (trim($url) === '') {
            throw new \InvalidArgumentException('A "url" is required');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(BrowserlessProfileConfig $profile, string $path, array $body): ResponseInterface
    {
        return $this->httpClient->request('POST', $profile->baseUrl . $path, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query' => ['token' => $profile->token],
            'json' => $body,
            // Rendering/Lighthouse can take a while; allow generous headroom.
            'timeout' => $profile->timeout,
        ]);
    }

    private function assertOk(ResponseInterface $response, string $action): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Browserless error while trying to %s (HTTP %d): %s',
                $action,
                $statusCode,
                $response->getContent(false),
            ));
        }
    }
}
