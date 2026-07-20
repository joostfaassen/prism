<?php

namespace App\Integrations\Instagram;

/**
 * Persists refreshed Instagram long-lived tokens back into the Prism config.
 *
 * Config is hand-maintained and contains comments, so we deliberately avoid a
 * full YAML round-trip (which would strip comments and reorder keys). Instead we
 * locate the target profile block by indentation and replace or insert only the
 * token keys, leaving everything else byte-for-byte intact.
 *
 * Two layouts are supported:
 *  - Per-server files `prism.{serverName}.yaml` (flat: `profiles:` at indent 0).
 *  - The legacy `prism.config.yaml` (nested: `servers: > {name}: > profiles:`).
 *
 * Mirrors the approach used by {@see \App\Integrations\Canva\CanvaTokenStore}.
 */
class InstagramTokenStore
{
    public function __construct(
        private readonly string $configPath,
    ) {
    }

    public function persistToken(
        string $serverName,
        string $profileKey,
        string $accessToken,
        int $tokenExpiresAt,
    ): void {
        $this->updateProfile($serverName, $profileKey, [
            'access_token' => $accessToken,
            'token_expires_at' => $tokenExpiresAt,
        ]);
    }

    /**
     * @param array<string, string|int> $values
     */
    private function updateProfile(string $serverName, string $profileKey, array $values): void
    {
        $perServer = \dirname($this->configPath) . '/prism.' . $serverName . '.yaml';

        if (is_file($perServer)) {
            $this->writeIntoFile($perServer, null, $profileKey, $values, 0);

            return;
        }

        $this->writeIntoFile($this->configPath, $serverName, $profileKey, $values, 4);
    }

    /**
     * @param array<string, string|int> $values
     */
    private function writeIntoFile(
        string $file,
        ?string $serverName,
        string $profileKey,
        array $values,
        int $profilesIndent,
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

        $profileKeyIndent = $profilesIndent + 2;
        $propIndent = $profilesIndent + 4;

        $profilesIdx = $this->findKey($lines, $searchStart, $searchEnd, $profilesIndent, 'profiles');
        if ($profilesIdx === null) {
            throw new \RuntimeException(sprintf('Could not locate an "profiles:" block in %s', $file));
        }
        $profilesEnd = $this->blockEnd($lines, $profilesIdx + 1, $profilesIndent);

        $profileIdx = $this->findKey($lines, $profilesIdx + 1, $profilesEnd, $profileKeyIndent, $profileKey);
        if ($profileIdx === null) {
            throw new \RuntimeException(sprintf('Could not locate profile "%s" in %s', $profileKey, $file));
        }

        foreach ($values as $key => $value) {
            $profileEnd = $this->blockEnd($lines, $profileIdx + 1, $profileKeyIndent);
            $formatted = str_repeat(' ', $propIndent) . $key . ': ' . $this->formatValue($value);
            $existing = $this->findKey($lines, $profileIdx + 1, $profileEnd, $propIndent, $key);

            if ($existing !== null) {
                $lines[$existing] = $formatted;
            } else {
                array_splice($lines, $profileIdx + 1, 0, [$formatted]);
            }
        }

        if (file_put_contents($file, implode($newline, $lines)) === false) {
            throw new \RuntimeException(sprintf('Unable to write config file: %s', $file));
        }
    }

    /**
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
