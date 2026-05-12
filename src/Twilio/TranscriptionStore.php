<?php

namespace App\Twilio;

class TranscriptionStore
{
    private readonly string $basePath;

    public function __construct(string $projectDir)
    {
        $this->basePath = $projectDir . '/var/transcriptions';
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function save(string $serverName, string $accountKey, string $callSid, array $metadata): void
    {
        $dir = $this->getDir($serverName, $accountKey);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $path = $dir . '/' . $callSid . '.json';
        file_put_contents($path, json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $serverName, string $accountKey, string $callSid): ?array
    {
        $path = $this->getDir($serverName, $accountKey) . '/' . $callSid . '.json';

        if (!file_exists($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function exists(string $serverName, string $accountKey, string $callSid): bool
    {
        return file_exists($this->getDir($serverName, $accountKey) . '/' . $callSid . '.json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $serverName, string $accountKey, ?string $search = null, int $limit = 50): array
    {
        $dir = $this->getDir($serverName, $accountKey);

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json');
        if ($files === false) {
            return [];
        }

        usort($files, fn(string $a, string $b) => filemtime($b) <=> filemtime($a));

        $results = [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            if ($search !== null) {
                $searchLower = mb_strtolower($search);
                $transcription = mb_strtolower($data['transcription'] ?? '');
                $from = mb_strtolower($data['from'] ?? '');
                $to = mb_strtolower($data['to'] ?? '');

                if (!str_contains($transcription, $searchLower)
                    && !str_contains($from, $searchLower)
                    && !str_contains($to, $searchLower)) {
                    continue;
                }
            }

            $results[] = $data;

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function getDir(string $serverName, string $accountKey): string
    {
        return $this->basePath . '/' . $serverName . '/' . $accountKey;
    }
}
