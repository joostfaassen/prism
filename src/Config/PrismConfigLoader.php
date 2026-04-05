<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class PrismConfigLoader
{
    private ?array $rawConfig = null;

    /** @var array<string, ServerConfig>|null */
    private ?array $servers = null;

    public function __construct(
        private readonly string $configPath,
    ) {
    }

    /**
     * @return array<string, ServerConfig>
     */
    public function getServers(): array
    {
        $this->load();

        return $this->servers;
    }

    public function getServer(string $name): ServerConfig
    {
        $servers = $this->getServers();

        if (!isset($servers[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown server: "%s". Available: %s',
                $name,
                implode(', ', array_keys($servers)),
            ));
        }

        return $servers[$name];
    }

    public function findServerByToken(string $token): ?ServerConfig
    {
        foreach ($this->getServers() as $server) {
            if ($server->bearerToken !== '' && $server->bearerToken === $token) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Filter accounts by type for the current server context.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAccountsByTypeForServer(string $type, ServerContext $serverContext): array
    {
        if (!$serverContext->hasServer()) {
            return [];
        }

        return $serverContext->getServer()->getAccountsByType($type);
    }

    private function load(): void
    {
        if ($this->rawConfig !== null) {
            return;
        }

        if (!file_exists($this->configPath)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $this->configPath));
        }

        $this->rawConfig = Yaml::parseFile($this->configPath);
        $this->servers = [];

        foreach (($this->rawConfig['servers'] ?? []) as $name => $cfg) {
            $this->servers[$name] = new ServerConfig(
                name: $name,
                label: $cfg['label'] ?? $name,
                bearerToken: $cfg['bearer_token'] ?? '',
                accounts: $cfg['accounts'] ?? [],
            );
        }
    }
}
