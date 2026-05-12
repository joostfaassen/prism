<?php

namespace App\AgentNotify;

class AgentNotifyPayload
{
    /**
     * @param array<string, mixed> $context Merged into webhook/OpenClaw payloads for structured agent routing (e.g. habits check-ins)
     */
    public function __construct(
        public readonly string $message,
        public readonly string $serverName,
        public readonly string $triggeredBy,
        public readonly array $context = [],
    ) {
    }
}
