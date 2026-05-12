<?php

namespace App\AgentNotify;

use App\Config\ServerConfig;

/**
 * Dispatches agent_notify using the strategy matching agent_notify.type.
 */
final class AgentNotifyService
{
    /** @var array<string, AgentNotifyClientInterface> */
    private array $clientsByType = [];

    /**
     * @param iterable<AgentNotifyClientInterface> $clients
     */
    public function __construct(
        iterable $clients,
    ) {
        foreach ($clients as $client) {
            $this->clientsByType[$client::getType()] = $client;
        }
    }

    public function notify(ServerConfig $server, AgentNotifyPayload $payload): AgentNotifyResult
    {
        $config = $server->getAgentNotify();
        if ($config === null || $config === []) {
            return new AgentNotifyResult(false, 0, '', 'agent_notify is not configured for this server');
        }

        $type = strtolower((string) ($config['type'] ?? ''));
        if ($type === '') {
            return new AgentNotifyResult(false, 0, '', 'agent_notify.type is missing');
        }

        $client = $this->clientsByType[$type] ?? null;
        if ($client === null) {
            return new AgentNotifyResult(
                false,
                0,
                '',
                sprintf(
                    'Unknown agent_notify.type "%s". Registered: %s',
                    $type,
                    implode(', ', array_keys($this->clientsByType)),
                ),
            );
        }

        return $client->notify($config, $payload);
    }
}
