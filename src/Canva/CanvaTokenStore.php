<?php

namespace App\Canva;

/**
 * Persists OAuth tokens back into the Prism config files.
 *
 * Config is hand-maintained and contains comments, so we deliberately avoid a
 * full YAML round-trip (which would strip comments and reorder keys). Instead we
 * locate the target account block by indentation and replace or insert only the
 * token keys, leaving everything else byte-for-byte intact.
 *
 * Two layouts are supported:
 *  - Per-server files `prism.{serverName}.yaml` (flat: `accounts:` at indent 0).
 *  - The legacy `prism.config.yaml` (nested: `servers: > {name}: > accounts:`).
 */
class CanvaTokenStore
{
    public function __construct(
        private readonly string $configPath,
    ) {
    }

    public function persistTokens(
        string $serverName,
        string $accountKey,
        string $accessToken,
        string $refreshToken,
        int $tokenExpiresAt,
    ): void {
        $this->updateAccount($serverName, $accountKey, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $tokenExpiresAt,
        ]);
    }

    public function clearTokens(string $serverName, string $accountKey): void
    {
        $this->updateAccount($serverName, $accountKey, [
            'access_token' => '',
            'refresh_token' => '',
            'token_expires_at' => 0,
        ]);
    }

    /**
     * Path of the config file that holds the given server, for display in the UI.
     */
    public function getTargetFile(string $serverName): string
    {
        $perServer = \dirname($this->configPath) . '/prism.' . $serverName . '.yaml';

        return is_file($perServer) ? $perServer : $this->configPath;
    }

    /**
     * @param array<string, string|int> $values
     */
    private function updateAccount(string $serverName, string $accountKey, array $values): void
    {
        $perServer = \dirname($this->configPath) . '/prism.' . $serverName . '.yaml';

        if (is_file($perServer)) {
            // Flat per-server file: accounts: at indent 0.
            $this->writeIntoFile($perServer, null, $accountKey, $values, 0);

            return;
        }

        // Legacy nested file: servers: > {serverName}: > accounts: at indent 4.
        $this->writeIntoFile($this->configPath, $serverName, $accountKey, $values, 4);
    }

    /**
     * @param array<string, string|int> $values
     */
    private function writeIntoFile(
        string $file,
        ?string $serverName,
        string $accountKey,
        array $values,
        int $accountsIndent,
    ): void {
        if (!is_file($file) || !is_writable($file)) {
            throw new \RuntimeException(sprintf('Config file is not writable: %s', $file));
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Unable to read config file: %s', $file));
        }

        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        $searchStart = 0;
        $searchEnd = count($lines);

        if ($serverName !== null) {
            $serversIdx = $this->findKey($lines, 0, count($lines), 0, 'servers');
            if ($serversIdx === null) {
                throw new \RuntimeException('Could not locate the "servers:" block in ' . $file);
            }
            $serversEnd = $this->blockEnd($lines, $serversIdx + 1, 0);

            $serverIdx = $this->findKey($lines, $serversIdx + 1, $serversEnd, 2, $serverName);
            if ($serverIdx === null) {
                throw new \RuntimeException(sprintf('Could not locate server "%s" in %s', $serverName, $file));
            }
            $searchStart = $serverIdx + 1;
            $searchEnd = $this->blockEnd($lines, $serverIdx + 1, 2);
        }

        $accountKeyIndent = $accountsIndent + 2;
        $propIndent = $accountsIndent + 4;

        $accountsIdx = $this->findKey($lines, $searchStart, $searchEnd, $accountsIndent, 'accounts');
        if ($accountsIdx === null) {
            throw new \RuntimeException(sprintf('Could not locate an "accounts:" block in %s', $file));
        }
        $accountsEnd = $this->blockEnd($lines, $accountsIdx + 1, $accountsIndent);

        $accountIdx = $this->findKey($lines, $accountsIdx + 1, $accountsEnd, $accountKeyIndent, $accountKey);
        if ($accountIdx === null) {
            throw new \RuntimeException(sprintf('Could not locate account "%s" in %s', $accountKey, $file));
        }

        foreach ($values as $key => $value) {
            $accountEnd = $this->blockEnd($lines, $accountIdx + 1, $accountKeyIndent);
            $formatted = str_repeat(' ', $propIndent) . $key . ': ' . $this->formatValue($value);
            $existing = $this->findKey($lines, $accountIdx + 1, $accountEnd, $propIndent, $key);

            if ($existing !== null) {
                $lines[$existing] = $formatted;
            } else {
                array_splice($lines, $accountIdx + 1, 0, [$formatted]);
            }
        }

        if (file_put_contents($file, implode($newline, $lines)) === false) {
            throw new \RuntimeException(sprintf('Unable to write config file: %s', $file));
        }
    }

    /**
     * Find the line index of a `{indent}{key}:` entry within [start, end).
     *
     * @param list<string> $lines
     */
    private function findKey(array $lines, int $start, int $end, int $indent, string $key): ?int
    {
        $prefix = str_repeat(' ', $indent) . $key . ':';
        $max = min($end, count($lines));

        for ($i = $start; $i < $max; $i++) {
            $line = $lines[$i];
            if ($line === $prefix
                || str_starts_with($line, $prefix . ' ')
                || str_starts_with($line, $prefix . "\t")) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Return the index of the first line that ends the block opened at the given
     * indentation (the first non-blank, non-comment line indented no deeper).
     *
     * @param list<string> $lines
     */
    private function blockEnd(array $lines, int $start, int $indent): int
    {
        $count = count($lines);

        for ($i = $start; $i < $count; $i++) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $lead = strlen($lines[$i]) - strlen(ltrim($lines[$i], ' '));
            if ($lead <= $indent) {
                return $i;
            }
        }

        return $count;
    }

    private function formatValue(string|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if ($value === '') {
            return '""';
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
