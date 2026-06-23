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

    /**
     * @return array<string, mixed>
     */
    public function getWhisperConfig(): array
    {
        $this->load();

        return $this->rawConfig['whisper'] ?? [];
    }

    private function load(): void
    {
        if ($this->rawConfig !== null) {
            return;
        }

        if (!file_exists($this->configPath)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $this->configPath));
        }

        $this->rawConfig = Yaml::parseFile($this->configPath) ?? [];
        $this->servers = [];

        // Servers defined inline in the main config file.
        foreach (($this->rawConfig['servers'] ?? []) as $name => $cfg) {
            $this->addServer((string) $name, $cfg);
        }

        // Servers defined in dedicated per-server files: prism.{serverName}.yaml
        // living next to the main config file. These keep large per-server
        // configs in separate, more editable files and override inline servers
        // with the same name.
        foreach ($this->discoverServerFiles() as $name => $file) {
            $cfg = Yaml::parseFile($file);
            if (!is_array($cfg)) {
                continue;
            }
            $this->addServer($name, $cfg);
        }
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function addServer(string $name, array $cfg): void
    {
        $agentNotify = $cfg['agent_notify'] ?? null;
        if (!is_array($agentNotify)) {
            $agentNotify = null;
        }

        $this->servers[$name] = new ServerConfig(
            name: $name,
            label: $cfg['label'] ?? $name,
            bearerToken: $cfg['bearer_token'] ?? '',
            accounts: $cfg['accounts'] ?? [],
            agentNotify: $agentNotify,
        );
    }

    /**
     * Find per-server config files (prism.{serverName}.yaml) next to the main
     * config file.
     *
     * @return array<string, string> map of serverName => absolute file path
     */
    private function discoverServerFiles(): array
    {
        $dir = \dirname($this->configPath);
        $mainBasename = basename($this->configPath);

        $files = glob($dir . '/prism.*.yaml') ?: [];
        $servers = [];

        foreach ($files as $file) {
            $basename = basename($file);

            // Skip the main config file itself (prism.config.yaml) and any
            // example/template files (prism.*.yaml.example never matches *.yaml).
            if ($basename === $mainBasename) {
                continue;
            }

            // Strip the "prism." prefix and ".yaml" suffix to get the server name.
            $name = substr($basename, \strlen('prism.'), -\strlen('.yaml'));
            if ($name === '' || $name === 'config') {
                continue;
            }

            $servers[$name] = $file;
        }

        return $servers;
    }
}
