<?php

namespace App\Config;

class ServerConfig
{
    /**
     * @param array<string, array<string, mixed>> $accounts Account configs keyed by name
     * @param array<string, mixed>|null          $agentNotify Optional per-server agent webhook (see prism.config.yaml)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $bearerToken,
        public readonly array $accounts,
        public readonly ?array $agentNotify = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAgentNotify(): ?array
    {
        return $this->agentNotify;
    }

    public function hasAgentNotify(): bool
    {
        return $this->agentNotify !== null
            && ($this->agentNotify['type'] ?? '') !== ''
            && ($this->agentNotify['webhook_url'] ?? '') !== '';
    }

    /**
     * @return list<string>
     */
    public function getAccountNames(): array
    {
        return array_keys($this->accounts);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAccountsByType(string $type): array
    {
        return array_filter(
            $this->accounts,
            fn(array $account) => ($account['type'] ?? '') === $type,
        );
    }

    /**
     * @return list<string> Unique account types present in this server
     */
    public function getAccountTypes(): array
    {
        $types = [];
        foreach ($this->accounts as $account) {
            $type = $account['type'] ?? '';
            if ($type !== '' && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public function hasAccountType(string $type): bool
    {
        return in_array($type, $this->getAccountTypes(), true);
    }
}
