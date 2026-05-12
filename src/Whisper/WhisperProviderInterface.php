<?php

namespace App\Whisper;

interface WhisperProviderInterface
{
    public function getName(): string;

    /**
     * @param array<string, mixed> $providerConfig Provider-specific configuration from prism.config.yaml
     */
    public function transcribe(string $audioFilePath, array $providerConfig, ?string $language = null): TranscriptionResult;
}
