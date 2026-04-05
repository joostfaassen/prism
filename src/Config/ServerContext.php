<?php

namespace App\Config;

class ServerContext
{
    private ?ServerConfig $server = null;

    public function setServer(ServerConfig $server): void
    {
        $this->server = $server;
    }

    public function getServer(): ServerConfig
    {
        if ($this->server === null) {
            throw new \LogicException('Server context not set. This should only be accessed during an MCP request.');
        }

        return $this->server;
    }

    public function hasServer(): bool
    {
        return $this->server !== null;
    }

    public function getServerName(): string
    {
        return $this->getServer()->name;
    }

    /**
     * @return list<string>
     */
    public function getAllowedAccountNames(): array
    {
        return $this->getServer()->getAccountNames();
    }

    public function isAccountAllowed(string $accountName): bool
    {
        return array_key_exists($accountName, $this->getServer()->accounts);
    }
}
