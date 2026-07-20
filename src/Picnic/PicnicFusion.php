<?php

namespace App\Picnic;

/**
 * Lightweight walkers for Picnic Fusion/PML JSON trees (no JSONPath dependency).
 */
class PicnicFusion
{
    /**
     * Collect every object found under a given key name (e.g. "sellingUnit").
     *
     * @return list<array<string, mixed>>
     */
    public static function collectKeyed(mixed $node, string $key): array
    {
        $out = [];
        self::walk($node, static function (array $item) use ($key, &$out): void {
            if (!array_key_exists($key, $item)) {
                return;
            }
            $value = $item[$key];
            if (is_array($value) && self::isList($value)) {
                foreach ($value as $entry) {
                    if (is_array($entry) && !self::isList($entry)) {
                        $out[] = $entry;
                    }
                }
            } elseif (is_array($value) && !self::isList($value)) {
                $out[] = $value;
            }
        });

        return $out;
    }

    /**
     * @param callable(array<string, mixed>): void $visitor
     */
    public static function walk(mixed $node, callable $visitor): void
    {
        if (is_array($node)) {
            if (!self::isList($node)) {
                $visitor($node);
            }
            foreach ($node as $child) {
                self::walk($child, $visitor);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function collectMarkdownLines(mixed $node, array $skipKeys = []): array
    {
        $skip = array_flip($skipKeys);
        $lines = [];
        $visit = function (mixed $n) use (&$visit, &$lines, $skip): void {
            if (is_array($n)) {
                if (!self::isList($n)) {
                    if (($n['type'] ?? null) === 'RICH_TEXT' && is_string($n['markdown'] ?? null)) {
                        foreach (preg_split('/\R/', (string) $n['markdown']) ?: [] as $raw) {
                            $cleaned = self::cleanLine($raw);
                            if ($cleaned !== '') {
                                $lines[] = $cleaned;
                            }
                        }
                    }
                    foreach ($n as $key => $child) {
                        if (isset($skip[$key])) {
                            continue;
                        }
                        $visit($child);
                    }

                    return;
                }
                foreach ($n as $child) {
                    $visit($child);
                }
            }
        };
        $visit($node);

        return $lines;
    }

    public static function cleanLine(string $line): string
    {
        $line = preg_replace('/#\(#[0-9a-fA-F]{6}\)/', '', $line) ?? $line;
        $line = preg_replace('/\[([^\]]+)\]\((?:action|app|https?):\/\/[^)]*\)/', '$1', $line) ?? $line;
        $line = preg_replace('/^\s*#{1,6}\s+/', '', $line) ?? $line;
        $line = preg_replace('/^\s*[-*+]\s+/', '', $line) ?? $line;
        $line = str_replace(['**', '__'], '', $line);
        $line = preg_replace('/\s+/', ' ', $line) ?? $line;

        return trim($line);
    }

    public static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
