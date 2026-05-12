<?php

namespace App\AgentNotify;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic JSON POST for agents that accept a message + metadata (Claude hooks, custom workers, etc.).
 *
 * YAML:
 *   type: webhook
 *   webhook_url: https://example.com/trigger
 *   bearer_token: optional
 *   headers: { X-Key: "value" }
 *   body: { "foo": "bar" }   # merged on top of default payload
 */
final class JsonWebhookAgentNotifyClient implements AgentNotifyClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public static function getType(): string
    {
        return 'webhook';
    }

    public function notify(array $config, AgentNotifyPayload $payload): AgentNotifyResult
    {
        $url = (string) ($config['webhook_url'] ?? '');
        if ($url === '') {
            return new AgentNotifyResult(false, 0, '', 'agent_notify.webhook_url is required');
        }

        $body = [
            'message' => $payload->message,
            'context' => array_replace_recursive(
                [
                    'source' => 'prism',
                    'server' => $payload->serverName,
                    'triggered_by' => $payload->triggeredBy,
                ],
                $payload->context,
            ),
        ];

        $merge = $config['body'] ?? null;
        if (is_array($merge)) {
            $body = array_replace_recursive($body, $merge);
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $extraHeaders = $config['headers'] ?? [];
        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $name => $value) {
                if (is_string($name) && (is_string($value) || is_numeric($value))) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        $token = (string) ($config['bearer_token'] ?? $config['token'] ?? '');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => (float) ($config['timeout_seconds'] ?? 30),
            ]);

            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
            $preview = strlen($raw) > 4000 ? substr($raw, 0, 4000) . '…' : $raw;
            $ok = $status >= 200 && $status < 300;

            return new AgentNotifyResult(
                success: $ok,
                httpStatus: $status,
                bodyPreview: $preview,
                errorMessage: $ok ? '' : sprintf('HTTP %d from webhook', $status),
            );
        } catch (\Throwable $e) {
            return new AgentNotifyResult(false, 0, '', $e->getMessage());
        }
    }
}
