<?php

namespace App\AgentNotify;

class AgentNotifyResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $httpStatus,
        public readonly string $bodyPreview,
        public readonly string $errorMessage = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'http_status' => $this->httpStatus,
            'body_preview' => $this->bodyPreview,
            'error' => $this->errorMessage,
        ];
    }
}
