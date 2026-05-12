<?php

namespace App\AgentNotify;

/**
 * One implementation per agent_notify "type" in prism.config.yaml.
 */
interface AgentNotifyClientInterface
{
    /**
     * Config value for server YAML key agent_notify.type
     */
    public static function getType(): string;

    /**
     * @param array<string, mixed> $config Server's agent_notify block
     */
    public function notify(array $config, AgentNotifyPayload $payload): AgentNotifyResult;
}
