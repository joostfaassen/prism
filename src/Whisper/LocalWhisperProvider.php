<?php

namespace App\Whisper;

use Symfony\Component\Process\Process;

class LocalWhisperProvider implements WhisperProviderInterface
{
    public function getName(): string
    {
        return 'local';
    }

    public function transcribe(string $audioFilePath, array $providerConfig, ?string $language = null): TranscriptionResult
    {
        $binary = $providerConfig['binary'] ?? 'whisper';
        $model = $providerConfig['model'] ?? 'base';
        $outputDir = $providerConfig['output_dir'] ?? sys_get_temp_dir();

        $command = [
            $binary,
            $audioFilePath,
            '--model', $model,
            '--output_format', 'json',
            '--output_dir', $outputDir,
        ];

        if ($language !== null) {
            $command[] = '--language';
            $command[] = $language;
        }

        if (isset($providerConfig['device'])) {
            $command[] = '--device';
            $command[] = $providerConfig['device'];
        }

        $process = new Process($command);
        $process->setTimeout($providerConfig['timeout'] ?? 300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Local Whisper failed (exit %d): %s',
                $process->getExitCode(),
                $process->getErrorOutput(),
            ));
        }

        $baseName = pathinfo($audioFilePath, PATHINFO_FILENAME);
        $jsonPath = $outputDir . '/' . $baseName . '.json';

        if (!file_exists($jsonPath)) {
            $rawOutput = $process->getOutput();

            return new TranscriptionResult(text: trim($rawOutput));
        }

        $data = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

        $text = $data['text'] ?? '';
        if ($text === '' && isset($data['segments'])) {
            $text = implode(' ', array_map(fn($s) => $s['text'] ?? '', $data['segments']));
        }

        return new TranscriptionResult(
            text: trim($text),
            language: $data['language'] ?? null,
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
        );
    }
}
