<?php

namespace App\Whisper;

class TranscriptionResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $language = null,
        public readonly ?float $duration = null,
    ) {
    }
}
