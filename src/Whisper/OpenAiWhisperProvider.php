<?php

namespace App\Whisper;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class OpenAiWhisperProvider implements WhisperProviderInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function transcribe(string $audioFilePath, array $providerConfig, ?string $language = null): TranscriptionResult
    {
        $apiKey = $providerConfig['api_key'] ?? '';
        if ($apiKey === '') {
            throw new \InvalidArgumentException('OpenAI Whisper provider requires an api_key');
        }

        $model = $providerConfig['model'] ?? 'whisper-1';
        $baseUrl = $providerConfig['base_url'] ?? null;
        $endpoint = $baseUrl ? rtrim($baseUrl, '/') . '/audio/transcriptions' : self::ENDPOINT;

        $formFields = [
            'model' => $model,
            'response_format' => 'verbose_json',
            'file' => DataPart::fromPath($audioFilePath),
        ];

        if ($language !== null) {
            $formFields['language'] = $language;
        }

        $formData = new FormDataPart($formFields);

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => array_merge(
                $formData->getPreparedHeaders()->toArray(),
                ['Authorization' => 'Bearer ' . $apiKey],
            ),
            'body' => $formData->bodyToIterable(),
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $body = $response->getContent(false);
            throw new \RuntimeException(sprintf('OpenAI Whisper API error (%d): %s', $statusCode, $body));
        }

        $data = $response->toArray();

        return new TranscriptionResult(
            text: $data['text'] ?? '',
            language: $data['language'] ?? null,
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
        );
    }
}
