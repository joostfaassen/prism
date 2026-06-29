<?php

namespace App\Browserless;

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
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'base_url' => $account->baseUrl,
            ];
        }

        return $accounts;
    }

    /**
     * Capture a screenshot of a page and return it base64-encoded.
     *
     * @param array<string, mixed> $options Puppeteer screenshot options (fullPage, type, quality, clip, ...)
     *
     * @return array{mime_type: string, bytes: int, base64: string}
     */
    public function screenshot(?string $accountKey, string $url, array $options = []): array
    {
        $account = $this->resolveAccount($accountKey);
        $this->assertUrl($url);

        $type = isset($options['type']) ? strtolower((string) $options['type']) : 'png';
        $mimeType = $type === 'jpeg' || $type === 'jpg' ? 'image/jpeg' : 'image/png';

        $body = ['url' => $url];
        if ($options !== []) {
            $body['options'] = $options;
        }

        $response = $this->post($account, '/screenshot', $body);
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
    public function content(?string $accountKey, string $url, array $extra = []): array
    {
        $account = $this->resolveAccount($accountKey);
        $this->assertUrl($url);

        $response = $this->post($account, '/content', array_merge($extra, ['url' => $url]));
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
    public function pdf(?string $accountKey, string $url, array $options = []): array
    {
        $account = $this->resolveAccount($accountKey);
        $this->assertUrl($url);

        $body = ['url' => $url];
        if ($options !== []) {
            $body['options'] = $options;
        }

        $response = $this->post($account, '/pdf', $body);
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
    public function performance(?string $accountKey, string $url, array $config = [], bool $full = false): array
    {
        $account = $this->resolveAccount($accountKey);
        $this->assertUrl($url);

        $body = ['url' => $url];
        if ($config !== []) {
            $body['config'] = $config;
        }

        $response = $this->post($account, '/performance', $body);
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

    private function resolveAccount(?string $accountKey): BrowserlessAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            $account = $this->configLoader->getAccount($accountKey);
        } else {
            $accounts = $this->configLoader->getAccounts();
            if ($accounts === []) {
                throw new \RuntimeException('No Browserless accounts configured for this server');
            }
            $account = reset($accounts);
        }

        if (!$account->hasCredentials()) {
            throw new \RuntimeException(sprintf(
                'Browserless account "%s" is missing base_url or token',
                $account->key,
            ));
        }

        return $account;
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
    private function post(BrowserlessAccountConfig $account, string $path, array $body): ResponseInterface
    {
        return $this->httpClient->request('POST', $account->baseUrl . $path, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'query' => ['token' => $account->token],
            'json' => $body,
            // Rendering/Lighthouse can take a while; allow generous headroom.
            'timeout' => $account->timeout,
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
