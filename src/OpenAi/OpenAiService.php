<?php

namespace App\OpenAi;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiService
{
    public function __construct(
        private readonly OpenAiConfigLoader $configLoader,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, base_url: string, default_model: string}>
     */
    public function listAccounts(): array
    {
        $accounts = [];
        foreach ($this->configLoader->getAccounts() as $key => $account) {
            $accounts[] = [
                'key' => $key,
                'label' => $account->label,
                'base_url' => $account->baseUrl,
                'default_model' => $account->defaultModel,
            ];
        }

        return $accounts;
    }

    /**
     * Run a chat completion against an OpenAI-compatible endpoint.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array{text: string, model: string, finish_reason: string|null, usage: array<string, mixed>|null}
     */
    public function complete(
        ?string $accountKey,
        array $messages,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
    ): array {
        $account = $this->resolveAccount($accountKey);

        if ($account->baseUrl === '' || $account->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'OpenAI account "%s" is missing base_url or api_key',
                $account->key,
            ));
        }

        $resolvedModel = $model ?? $account->defaultModel;
        if ($resolvedModel === '') {
            throw new \InvalidArgumentException(
                'No model provided and no default_model configured for this OpenAI account.',
            );
        }

        if ($messages === []) {
            throw new \InvalidArgumentException('At least one message is required.');
        }

        $body = [
            'model' => $resolvedModel,
            'messages' => $messages,
        ];

        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }
        if ($maxTokens !== null) {
            $body['max_tokens'] = $maxTokens;
        }

        $data = $this->request($account, 'POST', '/chat/completions', ['json' => $body]);

        $choice = $data['choices'][0] ?? null;
        $text = $choice['message']['content'] ?? '';

        return [
            'text' => is_string($text) ? $text : (string) json_encode($text),
            'model' => $data['model'] ?? $resolvedModel,
            'finish_reason' => $choice['finish_reason'] ?? null,
            'usage' => $data['usage'] ?? null,
        ];
    }

    /**
     * List the models available on the endpoint.
     *
     * @return list<array<string, mixed>>
     */
    public function listModels(?string $accountKey = null): array
    {
        $account = $this->resolveAccount($accountKey);

        if ($account->baseUrl === '' || $account->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'OpenAI account "%s" is missing base_url or api_key',
                $account->key,
            ));
        }

        $data = $this->request($account, 'GET', '/models');

        $models = $data['data'] ?? [];
        if (!is_array($models)) {
            return [];
        }

        return array_values($models);
    }

    private function resolveAccount(?string $accountKey): OpenAiAccountConfig
    {
        if ($accountKey !== null && $accountKey !== '') {
            return $this->configLoader->getAccount($accountKey);
        }

        $accounts = $this->configLoader->getAccounts();
        if (empty($accounts)) {
            throw new \RuntimeException('No OpenAI accounts configured for this server');
        }

        return reset($accounts);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(OpenAiAccountConfig $account, string $method, string $path, array $options = []): array
    {
        $response = $this->httpClient->request($method, $account->baseUrl . $path, array_merge([
            'auth_bearer' => $account->apiKey,
            'timeout' => $account->timeout,
        ], $options));

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'OpenAI API error (HTTP %d): %s',
                $statusCode,
                $response->getContent(false),
            ));
        }

        $content = $response->getContent();
        if ($content === '') {
            return [];
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : ['value' => $data];
    }
}
