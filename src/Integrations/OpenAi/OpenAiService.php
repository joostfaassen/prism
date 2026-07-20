<?php

namespace App\Integrations\OpenAi;

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
    public function listProfiles(): array
    {
        $profiles = [];
        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $profiles[] = [
                'key' => $key,
                'label' => $profile->label,
                'base_url' => $profile->baseUrl,
                'default_model' => $profile->defaultModel,
            ];
        }

        return $profiles;
    }

    /**
     * Run a chat completion against an OpenAI-compatible endpoint.
     *
     * @param list<array{role: string, content: string}> $messages
     *
     * @return array{text: string, model: string, finish_reason: string|null, usage: array<string, mixed>|null}
     */
    public function complete(
        ?string $profileKey,
        array $messages,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
    ): array {
        $profile = $this->resolveProfile($profileKey);

        if ($profile->baseUrl === '' || $profile->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'OpenAI profile "%s" is missing base_url or api_key',
                $profile->key,
            ));
        }

        $resolvedModel = $model ?? $profile->defaultModel;
        if ($resolvedModel === '') {
            throw new \InvalidArgumentException(
                'No model provided and no default_model configured for this OpenAI profile.',
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

        $data = $this->request($profile, 'POST', '/chat/completions', ['json' => $body]);

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
    public function listModels(?string $profileKey = null): array
    {
        $profile = $this->resolveProfile($profileKey);

        if ($profile->baseUrl === '' || $profile->apiKey === '') {
            throw new \RuntimeException(sprintf(
                'OpenAI profile "%s" is missing base_url or api_key',
                $profile->key,
            ));
        }

        $data = $this->request($profile, 'GET', '/models');

        $models = $data['data'] ?? [];
        if (!is_array($models)) {
            return [];
        }

        return array_values($models);
    }

    private function resolveProfile(?string $profileKey): OpenAiProfileConfig
    {
        if ($profileKey !== null && $profileKey !== '') {
            return $this->configLoader->getProfile($profileKey);
        }

        $profiles = $this->configLoader->getProfiles();
        if (empty($profiles)) {
            throw new \RuntimeException('No OpenAI profiles configured for this server');
        }

        return reset($profiles);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(OpenAiProfileConfig $profile, string $method, string $path, array $options = []): array
    {
        $response = $this->httpClient->request($method, $profile->baseUrl . $path, array_merge([
            'auth_bearer' => $profile->apiKey,
            'timeout' => $profile->timeout,
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
