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

    /**
     * Decide whether a tool is exposed on this server.
     *
     * Accounts may declare an optional `tools` allowlist (a list of tool-name
     * patterns, with `*`/`?` wildcards) to restrict which of their type's tools
     * are exposed. The allowlist is collected across every account of the tool's
     * type:
     *
     *  - If no account of that type declares a `tools` list, all of that type's
     *    tools are exposed (backwards compatible).
     *  - Otherwise, the tool is exposed only if its name matches at least one
     *    pattern from the union of those allowlists.
     *
     * Utility tools (those without an account type) are always allowed.
     */
    public function isToolAllowed(string $toolName, ?string $accountType): bool
    {
        if ($accountType === null) {
            return true;
        }

        $patterns = [];
        $hasAllowlist = false;

        foreach ($this->getAccountsByType($accountType) as $account) {
            if (!array_key_exists('tools', $account)) {
                continue;
            }

            $hasAllowlist = true;
            foreach ((array) $account['tools'] as $pattern) {
                if (is_string($pattern) && $pattern !== '') {
                    $patterns[] = $pattern;
                }
            }
        }

        if (!$hasAllowlist) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($toolName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a tool name against a glob-style pattern supporting `*` and `?`.
     */
    private function matchesPattern(string $name, string $pattern): bool
    {
        if ($pattern === $name) {
            return true;
        }

        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $name) === 1;
    }
}
