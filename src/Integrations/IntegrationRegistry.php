<?php

namespace App\Integrations;

class IntegrationRegistry
{
    /** @var array<string, IntegrationInterface> keyed by type */
    private array $integrations = [];

    /**
     * @param iterable<IntegrationInterface> $integrations
     */
    public function __construct(iterable $integrations)
    {
        foreach ($integrations as $integration) {
            $this->integrations[$integration->getType()] = $integration;
        }
        ksort($this->integrations);
    }

    /** @return array<string, IntegrationInterface> sorted by type */
    public function all(): array
    {
        return $this->integrations;
    }

    public function get(string $type): ?IntegrationInterface
    {
        return $this->integrations[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->integrations[$type]);
    }
}
