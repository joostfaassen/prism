<?php

namespace App\AgentNotify;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POSTs to an OpenClaw gateway hook URL (e.g. /hooks/agent).
 *
 * YAML (server.agent_notify):
 *   type: openclaw
 *   webhook_url: https://gateway.example/hooks/agent
 *   bearer_token: optional   # Authorization: Bearer …
 *   token_header: x-openclaw-token  # alternative header name (value from bearer_token)
 *   agent_id: hooks
 *   channel: last
 *   deliver: true
 *   wake_mode: now
 *   name: Prism
 *   session_key: optional   # default: prism:{server}:{random}
 */
final class OpenClawAgentNotifyClient implements AgentNotifyClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public static function getType(): string
    {
        return 'openclaw';
    }

    public function notify(array $config, AgentNotifyPayload $payload): AgentNotifyResult
    {
        $url = (string) ($config['webhook_url'] ?? '');
        if ($url === '') {
            return new AgentNotifyResult(false, 0, '', 'agent_notify.webhook_url is required');
        }

        $body = $this->buildJsonBody($config, $payload);

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
            $customHeader = isset($config['token_header']) ? (string) $config['token_header'] : '';
            if ($customHeader !== '' && strtolower($customHeader) !== 'authorization') {
                $headers[$customHeader] = $token;
            } else {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => (float) ($config['timeout_seconds'] ?? 30),
            ]);

            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
            $preview = $this->truncate($raw, 4000);

            $ok = $status >= 200 && $status < 300;

            return new AgentNotifyResult(
                success: $ok,
                httpStatus: $status,
                bodyPreview: $preview,
                errorMessage: $ok ? '' : sprintf('HTTP %d from OpenClaw webhook', $status),
            );
        } catch (\Throwable $e) {
            return new AgentNotifyResult(false, 0, '', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function buildJsonBody(array $config, AgentNotifyPayload $payload): array
    {
        $sessionKey = (string) ($config['session_key'] ?? '');
        if ($sessionKey === '') {
            $sessionKey = 'prism:' . $payload->serverName . ':' . bin2hex(random_bytes(8));
        }

        $body = [
            'message' => $payload->message,
            'sessionKey' => $sessionKey,
            'wakeMode' => (string) ($config['wake_mode'] ?? 'now'),
        ];

        if ($payload->context !== []) {
            $body['metadata'] = array_replace_recursive(
                $body['metadata'] ?? [],
                ['prism_context' => $payload->context],
            );
        }

        if (isset($config['agent_id']) && $config['agent_id'] !== '') {
            $body['agentId'] = (string) $config['agent_id'];
        }
        if (isset($config['channel']) && $config['channel'] !== '') {
            $body['channel'] = (string) $config['channel'];
        }
        if (array_key_exists('deliver', $config)) {
            $body['deliver'] = (bool) $config['deliver'];
        }
        if (isset($config['name']) && $config['name'] !== '') {
            $body['name'] = (string) $config['name'];
        }
        if (isset($config['model']) && $config['model'] !== '') {
            $body['model'] = (string) $config['model'];
        }
        if (isset($config['thinking']) && $config['thinking'] !== '') {
            $body['thinking'] = (string) $config['thinking'];
        }
        if (isset($config['timeout_seconds']) && is_numeric($config['timeout_seconds'])) {
            $body['timeoutSeconds'] = (int) $config['timeout_seconds'];
        }

        $merge = $config['body'] ?? $config['extra_body'] ?? null;
        if (is_array($merge)) {
            $body = array_replace($body, $merge);
        }

        return $body;
    }

    private function truncate(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . '…';
    }
}
