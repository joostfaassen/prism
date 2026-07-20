<?php

namespace App\Integrations\Twilio;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TwilioService
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01/Profiles';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TwilioConfigLoader $configLoader,
    ) {
    }

    /**
     * @return array{calls: list<array<string, mixed>>, page_info: array<string, mixed>}
     */
    public function listCalls(string $profileKey, array $filters = []): array
    {
        $profile = $this->configLoader->getProfile($profileKey);

        $query = ['PageSize' => $filters['limit'] ?? 20];
        if (isset($filters['status'])) {
            $query['Status'] = $filters['status'];
        }
        if (isset($filters['from'])) {
            $query['From'] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $query['To'] = $filters['to'];
        }
        if (isset($filters['start_time_after'])) {
            $query['StartTime>'] = $filters['start_time_after'];
        }
        if (isset($filters['start_time_before'])) {
            $query['StartTime<'] = $filters['start_time_before'];
        }
        if (isset($filters['page_token'])) {
            $query['PageToken'] = $filters['page_token'];
        }

        $data = $this->request('GET', "/{$profile->accountSid}/Calls.json", $profile, [
            'query' => $query,
        ]);

        return [
            'calls' => $data['calls'] ?? [],
            'page_info' => [
                'page' => $data['page'] ?? 0,
                'page_size' => $data['page_size'] ?? 20,
                'next_page_uri' => $data['next_page_uri'] ?? null,
                'previous_page_uri' => $data['previous_page_uri'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCall(string $profileKey, string $callSid): array
    {
        $profile = $this->configLoader->getProfile($profileKey);

        return $this->request('GET', "/{$profile->accountSid}/Calls/{$callSid}.json", $profile);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecordings(string $profileKey, string $callSid): array
    {
        $profile = $this->configLoader->getProfile($profileKey);

        $data = $this->request(
            'GET',
            "/{$profile->accountSid}/Calls/{$callSid}/Recordings.json",
            $profile,
        );

        return $data['recordings'] ?? [];
    }

    public function getRecordingMediaUrl(string $profileKey, string $recordingSid): string
    {
        $profile = $this->configLoader->getProfile($profileKey);

        return self::BASE_URL . "/{$profile->accountSid}/Recordings/{$recordingSid}.mp3";
    }

    /**
     * Download a recording's audio to the local filesystem.
     */
    public function downloadRecording(string $profileKey, string $recordingSid, string $targetPath): void
    {
        $profile = $this->configLoader->getProfile($profileKey);
        $url = self::BASE_URL . "/{$profile->accountSid}/Recordings/{$recordingSid}.mp3";

        $response = $this->httpClient->request('GET', $url, [
            'auth_basic' => [$profile->accountSid, $profile->authToken],
        ]);

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($targetPath, $response->getContent());
    }

    /**
     * @return list<array{key: string, label: string, account_sid: string}>
     */
    public function listProfiles(): array
    {
        $result = [];

        foreach ($this->configLoader->getProfiles() as $key => $profile) {
            $result[] = [
                'key' => $key,
                'label' => $profile->label,
                'account_sid' => $profile->accountSid,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, TwilioProfileConfig $profile, array $options = []): array
    {
        $response = $this->httpClient->request($method, self::BASE_URL . $path, array_merge([
            'auth_basic' => [$profile->accountSid, $profile->authToken],
        ], $options));

        $statusCode = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Twilio API error (%d): %s',
                $data['code'] ?? $statusCode,
                $data['message'] ?? 'Unknown error',
            ));
        }

        return $data;
    }
}
